<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * AtlasCloudVideoClient — the AtlasCloud async VIDEO adapter (its generateVideo task API).
 *
 * Mirrors BytePlusVideoClient (bearer key, submit->id, poll->terminal state, bounded mp4 download,
 * masked logging, classified failures) but for AtlasCloud's shape:
 *   - submitTask()  POSTs /model/generateVideo (prompt + reference_images) and returns data.id;
 *   - pollTask()    GETs /model/prediction/{id}; the video url is data.outputs[0].
 *
 * Two AtlasCloud-specific concerns:
 *   1. Reference reachability: AtlasCloud must FETCH each reference image, but the media disk may
 *      not be publicly reachable, so any http(s) reference url is downloaded here and sent as a
 *      base64 data URI instead of the bare url.
 *   2. Shape normalization: pollTask() returns the SAME normalized array shape the pollers already
 *      read for BytePlus (status/content.video_url/error.message/created_at/updated_at), mapping
 *      AtlasCloud 'completed' -> 'succeeded'. So the pollers need no per-provider branching.
 *
 * NO USD cost is returned (video is flat-rate) — the caller applies the admin per-clip price.
 * Failures classify into the shared OpenRouterException codes. Storyboard clips NEVER charge.
 */
final class AtlasCloudVideoClient implements VideoGenerationProvider
{
    // === CONSTANTS ===
    private const SUBMIT_PATH = '/model/generateVideo';
    private const PREDICTION_PATH = '/model/prediction/'; // + {id}

    private const CFG_BASE_URL = 'services.atlascloud.base_url';
    private const CFG_TIMEOUT = 'services.atlascloud.timeout';

    // Explicit per-call HTTP timeouts (seconds). A poll THEN a download fire together, so their sum
    // must stay UNDER the media-queue worker timeout (120s) — 30 + 60 = 90.
    private const POLL_TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 60;
    // A reference image is fetched during submit; keep it short (submit fits the 70s gen timeout).
    private const IMAGE_FETCH_TIMEOUT = 30;

    // Only these video knobs are sent (unknown keys risk a 400). Defaults are broadly supported.
    private const DEFAULT_RESOLUTION = '720p';
    private const DEFAULT_DURATION = 5;
    private const DEFAULT_RATIO = 'adaptive';

    private const MAX_VIDEO_BYTES = 104_857_600;   // 100 MiB result-download ceiling
    private const MAX_IMAGE_BYTES = 12_582_912;    // 12 MiB per reference-image download ceiling
    private const DEFAULT_IMAGE_MIME = 'image/png';
    private const DATA_URI_PREFIX = 'data:';

    // AtlasCloud success states, both normalized to STATUS_SUCCEEDED for the pollers.
    private const RAW_SUCCESS = ['completed', 'succeeded'];
    private const STATUS_SUCCEEDED = 'succeeded';
    private const STATUS_FAILED = 'failed';
    // Raw AtlasCloud failure states normalized onto STATUS_FAILED (in the shared TERMINAL_FAILURE set).
    private const RAW_FAILURE = ['failed', 'cancelled', 'canceled', 'expired', 'error'];

    private const STATUS_RATE_LIMITED = 429;
    private const STATUS_SERVER_MIN = 500;

    private const KEY_VISIBLE_PREFIX = 8;
    private const KEY_MASK = '****';

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Submit a video generation task; returns the prediction id (data.id). $imageUrls are optional
     * input frames — each http(s) url is downloaded and sent as a base64 data URI so AtlasCloud can
     * always read it; a non-http entry (data URI / asset://ID) is passed through unchanged.
     *
     * @param  array<int,string>  $imageUrls
     * @param  array<string,mixed>  $params  resolution / duration_seconds / ratio
     */
    public function submitTask(string $model, string $prompt, array $imageUrls, array $params = [], ?string $baseUrl = null): string
    {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'reference_images' => $this->referenceImages($imageUrls),
            'resolution' => (string) ($params['resolution'] ?? self::DEFAULT_RESOLUTION),
            'duration' => (int) ($params['duration_seconds'] ?? self::DEFAULT_DURATION),
            'ratio' => (string) ($params['ratio'] ?? self::DEFAULT_RATIO),
            'watermark' => false,
        ];

        try {
            $response = $this->request($baseUrl)->post(self::SUBMIT_PATH, $body);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('AtlasCloud video submit timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        if (! $response->successful()) {
            $this->log($model, 'submit_error', $response->status());

            throw OpenRouterException::make(
                $this->classify($response->status()),
                sprintf('AtlasCloud video submit error (%d) for model %s: %s', $response->status(), $model, $this->errorMessage($response)),
                modelUsed: $model,
                providerStatus: $response->status(),
            );
        }

        $id = $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'AtlasCloud video submit returned no prediction id.',
                modelUsed: $model,
            );
        }

        $this->log($model, 'submitted', $response->status());

        return $id;
    }

    /**
     * Poll a prediction and return the NORMALIZED task array the pollers already read for BytePlus:
     * status (succeeded|failed|<processing>), content.video_url, error.message, created_at,
     * updated_at. AtlasCloud carries no created/updated span, so those are 0 (render time unknown).
     * Never throws for a terminal FAILED task; throws only on transport/HTTP errors so the caller
     * can reschedule the poll.
     *
     * @return array<string,mixed>
     */
    public function pollTask(string $taskId, ?string $baseUrl = null): array
    {
        try {
            $response = $this->request($baseUrl, self::POLL_TIMEOUT)->get(self::PREDICTION_PATH.$taskId);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                'AtlasCloud video poll timed out.',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw OpenRouterException::make(
                $this->classify($response->status()),
                sprintf('AtlasCloud video poll error (%d).', $response->status()),
                providerStatus: $response->status(),
            );
        }

        return $this->normalize(is_array($response->json()) ? $response->json() : []);
    }

    /** True when a polled (normalized) task has completed successfully. */
    public function succeeded(array $task): bool
    {
        return ($task['status'] ?? '') === self::STATUS_SUCCEEDED;
    }

    /** Download the result MP4 bytes from the video url, bounded. Null if unusable. */
    public function downloadVideo(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $response = $this->http->timeout(self::DOWNLOAD_TIMEOUT)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();

        return strlen($bytes) <= self::MAX_VIDEO_BYTES ? $bytes : null;
    }

    /**
     * Map AtlasCloud's response onto the shared normalized shape. data.status is normalized to
     * succeeded/failed/<processing>; data.outputs[0] becomes content.video_url; data.error becomes
     * error.message; created_at/updated_at are 0 (AtlasCloud reports no render span).
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function normalize(array $raw): array
    {
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $rawStatus = strtolower((string) ($data['status'] ?? ''));

        $status = match (true) {
            in_array($rawStatus, self::RAW_SUCCESS, true) => self::STATUS_SUCCEEDED,
            in_array($rawStatus, self::RAW_FAILURE, true) => self::STATUS_FAILED,
            default => $rawStatus, // a processing state passes through (the poller reschedules)
        };

        $outputs = is_array($data['outputs'] ?? null) ? $data['outputs'] : [];
        $videoUrl = is_string($outputs[0] ?? null) ? $outputs[0] : null;

        return [
            'status' => $status,
            'content' => ['video_url' => $videoUrl],
            'error' => ['message' => is_string($data['error'] ?? null) ? $data['error'] : null],
            'created_at' => 0,
            'updated_at' => 0,
        ];
    }

    /**
     * Build the reference_images entries: an http(s) url is downloaded and encoded as a data URI so
     * AtlasCloud can read a private-disk image; a non-http entry is already a data URI / asset://ID.
     *
     * @param  array<int,string>  $imageUrls
     * @return array<int,string>
     */
    private function referenceImages(array $imageUrls): array
    {
        $out = [];

        foreach (array_values($imageUrls) as $url) {
            if ($url === '') {
                continue;
            }

            $out[] = str_starts_with($url, 'http') ? ($this->toDataUri($url) ?? $url) : $url;
        }

        return $out;
    }

    /** Download an image url and return it as a base64 data URI, or null when the fetch is unusable. */
    private function toDataUri(string $url): ?string
    {
        try {
            $response = $this->http->timeout(self::IMAGE_FETCH_TIMEOUT)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return self::DATA_URI_PREFIX.$this->imageMime($response).';base64,'.base64_encode($bytes);
    }

    /** The image mime from the response Content-Type, defaulting to PNG when it is not an image/*. */
    private function imageMime(Response $response): string
    {
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return str_starts_with($type, 'image/') ? $type : self::DEFAULT_IMAGE_MIME;
    }

    private function request(?string $baseUrl, ?int $timeout = null): PendingRequest
    {
        return $this->http
            ->baseUrl($baseUrl !== null && $baseUrl !== '' ? $baseUrl : (string) config(self::CFG_BASE_URL))
            ->timeout($timeout ?? (int) config(self::CFG_TIMEOUT))
            ->withToken((string) app(PlatformSettings::class)->resolve(PlatformSettings::ATLASCLOUD_API_KEY))
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        $error = $response->json('error');
        if (is_array($error)) {
            return (string) ($error['message'] ?? '');
        }

        return is_string($error) ? $error : (string) $response->body();
    }

    private function classify(int $status): string
    {
        if ($status === self::STATUS_RATE_LIMITED) {
            return OpenRouterException::CODE_RATE_LIMITED;
        }

        if ($status >= self::STATUS_SERVER_MIN) {
            return OpenRouterException::CODE_PROVIDER_OUTAGE;
        }

        return OpenRouterException::CODE_BAD_REQUEST;
    }

    private function log(string $model, string $outcome, int $status): void
    {
        Log::info('atlascloud.video', [
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskKey(),
        ]);
    }

    /** Mask the SAME key the request sends (DB value first, then config) so the log reflects reality. */
    private function maskKey(): string
    {
        $key = (string) app(PlatformSettings::class)->resolve(PlatformSettings::ATLASCLOUD_API_KEY);

        return $key === '' ? '' : substr($key, 0, self::KEY_VISIBLE_PREFIX).self::KEY_MASK;
    }
}
