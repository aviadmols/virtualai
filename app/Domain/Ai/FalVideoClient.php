<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Concerns\EncodesImageDataUris;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * FalVideoClient — the fal.ai async VIDEO adapter (queue API).
 *
 * Mirrors AtlasCloudVideoClient (submit → id, poll → terminal state, bounded mp4 download, masked
 * logging) but for fal's queue shape:
 *   - submitTask()  POSTs https://queue.fal.run/{model} and returns a COMPOSITE task id
 *     "{model}|{request_id}" — fal's status/result routes need the model path, and the
 *     VideoGenerationProvider contract passes back only the opaque task id;
 *   - pollTask()    GETs the status; IN_QUEUE/IN_PROGRESS pass through as a processing state,
 *     COMPLETED fetches the result and maps video.url onto the SAME normalized array the pollers
 *     already read for BytePlus/AtlasCloud (status/content.video_url/error.message).
 *
 * Input frames are inlined as base64 data URIs (fal documents data-URI inputs) because the media
 * disk may not be publicly reachable. Only prompt + image url(s) are sent — per-model knobs
 * (duration/resolution enums) differ across fal's catalog, and an unknown value is a 422, so the
 * model's own defaults apply. NO USD cost is returned (video is flat-rate) — the caller applies
 * the admin per-clip price. Storyboard clips NEVER charge.
 */
final class FalVideoClient implements VideoGenerationProvider
{
    use EncodesImageDataUris;

    // === CONSTANTS ===
    private const REQUESTS_SEGMENT = '/requests/';
    private const STATUS_SUFFIX = '/status';
    // The result lives at .../requests/{id}/response (the submit reply's response_url shape); some
    // deployments still serve the bare .../requests/{id}, kept as a one-shot fallback.
    private const RESULT_SUFFIX = '/response';
    private const ROUTE_MISMATCH_STATUSES = [404, 405];
    private const TASK_ID_SEPARATOR = '|';

    private const CFG_BASE_URL = 'services.fal.base_url';
    private const CFG_TIMEOUT = 'services.fal.timeout';

    // Explicit per-call HTTP timeouts (seconds); a poll THEN a download fire together, so their
    // sum must stay UNDER the media-queue worker timeout (120s) — 30 + 60 = 90.
    private const POLL_TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 60;

    private const MAX_VIDEO_BYTES = 104_857_600; // 100 MiB result-download ceiling

    // fal queue statuses, normalized onto the shared poller vocabulary.
    private const RAW_COMPLETED = 'COMPLETED';
    private const RAW_IN_FLIGHT = ['IN_QUEUE', 'IN_PROGRESS'];
    private const STATUS_SUCCEEDED = 'succeeded';
    private const STATUS_FAILED = 'failed';
    private const STATUS_PROCESSING = 'processing';

    private const STATUS_RATE_LIMITED = 429;
    private const STATUS_SERVER_MIN = 500;

    private const KEY_VISIBLE_PREFIX = 8;
    private const KEY_MASK = '****';

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Submit a video generation; returns the composite task id "{app}|{request_id}", where {app}
     * is the queue app path taken from the submit reply's OWN status_url (fal may route a nested
     * model id off a shorter base app — constructing from the model path can 404/405 and leave the
     * poll spinning forever). The first input frame is sent as image_url (+ image_urls when more
     * exist) — both inlined as data URIs.
     *
     * @param  array<int,string>  $imageUrls
     * @param  array<string,mixed>  $params  ignored — fal knobs are per-model enums (see docblock)
     */
    public function submitTask(string $model, string $prompt, array $imageUrls, array $params = [], ?string $baseUrl = null): string
    {
        $body = ['prompt' => $prompt];

        $inlined = $this->asDataUris($imageUrls);
        if ($inlined !== []) {
            $body['image_url'] = $inlined[0];
            if (count($inlined) > 1) {
                $body['image_urls'] = $inlined;
            }
        }

        try {
            $response = $this->request($baseUrl)->post('/'.$model, $body);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('fal video submit timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        if (! $response->successful()) {
            $this->log($model, 'submit_error', $response->status());

            throw OpenRouterException::make(
                $this->classify($response->status()),
                sprintf('fal video submit error (%d) for model %s: %s', $response->status(), $model, $this->errorMessage($response)),
                modelUsed: $model,
                providerStatus: $response->status(),
            );
        }

        $id = $response->json('request_id');
        if (! is_string($id) || $id === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'fal video submit returned no request id.',
                modelUsed: $model,
            );
        }

        $this->log($model, 'submitted', $response->status());

        return $this->queueApp((string) $response->json('status_url'), $model).self::TASK_ID_SEPARATOR.$id;
    }

    /**
     * The queue app path fal ACTUALLY routed this request under: the segment of the submit reply's
     * status_url between the host and '/requests/'. Falls back to the model path when the reply
     * carries no usable status_url.
     */
    private function queueApp(string $statusUrl, string $model): string
    {
        $path = (string) parse_url($statusUrl, PHP_URL_PATH);
        $requests = strpos($path, self::REQUESTS_SEGMENT);

        if ($requests === false || $requests === 0) {
            return $model;
        }

        return ltrim(substr($path, 0, $requests), '/');
    }

    /**
     * Poll a composite task id and return the NORMALIZED task array the pollers already read:
     * status (succeeded|failed|processing), content.video_url, error.message, created_at,
     * updated_at (0 — fal reports no render span here). Never throws for a terminal FAILED task;
     * throws only on transport/HTTP errors so the caller can reschedule the poll.
     *
     * @return array<string,mixed>
     */
    public function pollTask(string $taskId, ?string $baseUrl = null): array
    {
        [$model, $requestId] = $this->splitTaskId($taskId);

        try {
            $response = $this->request($baseUrl, self::POLL_TIMEOUT)
                ->get('/'.$model.self::REQUESTS_SEGMENT.$requestId.self::STATUS_SUFFIX);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                'fal video poll timed out.',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw OpenRouterException::make(
                $this->classify($response->status()),
                sprintf('fal video poll error (%d).', $response->status()),
                providerStatus: $response->status(),
            );
        }

        $status = strtoupper((string) $response->json('status'));

        if (in_array($status, self::RAW_IN_FLIGHT, true)) {
            return $this->normalized(self::STATUS_PROCESSING, null, null);
        }

        if ($status !== self::RAW_COMPLETED) {
            return $this->normalized(self::STATUS_FAILED, null, 'fal reported status "'.$status.'".');
        }

        return $this->fetchResult($model, $requestId, $baseUrl);
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

    /** COMPLETED → fetch the result body and map video.url / error onto the normalized shape. @return array<string,mixed> */
    private function fetchResult(string $model, string $requestId, ?string $baseUrl): array
    {
        $requestBase = '/'.$model.self::REQUESTS_SEGMENT.$requestId;
        $response = $this->getResult($requestBase.self::RESULT_SUFFIX, $baseUrl);

        // A 404/405 route mismatch retries ONCE on the bare request url.
        if (in_array($response->status(), self::ROUTE_MISMATCH_STATUSES, true)) {
            $response = $this->getResult($requestBase, $baseUrl);
        }

        $decoded = is_array($response->json()) ? $response->json() : [];
        $videoUrl = data_get($decoded, 'video.url');

        if ($response->successful() && is_string($videoUrl) && $videoUrl !== '') {
            return $this->normalized(self::STATUS_SUCCEEDED, $videoUrl, null);
        }

        return $this->normalized(self::STATUS_FAILED, null, $this->errorMessage($response) ?: 'fal returned no video url.');
    }

    private function getResult(string $path, ?string $baseUrl): Response
    {
        try {
            return $this->request($baseUrl, self::POLL_TIMEOUT)->get($path);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                'fal video result fetch timed out.',
                previous: $e,
            );
        }
    }

    /** @return array<string,mixed> */
    private function normalized(string $status, ?string $videoUrl, ?string $error): array
    {
        return [
            'status' => $status,
            'content' => ['video_url' => $videoUrl],
            'error' => ['message' => $error],
            'created_at' => 0,
            'updated_at' => 0,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function splitTaskId(string $taskId): array
    {
        $separator = strrpos($taskId, self::TASK_ID_SEPARATOR);

        if ($separator === false) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'fal video task id is missing its model segment.',
            );
        }

        return [substr($taskId, 0, $separator), substr($taskId, $separator + 1)];
    }

    private function request(?string $baseUrl, ?int $timeout = null): PendingRequest
    {
        // fal's auth scheme is `Authorization: Key <FAL_KEY>` — NOT a Bearer token.
        return $this->http
            ->baseUrl($baseUrl !== null && $baseUrl !== '' ? $baseUrl : (string) config(self::CFG_BASE_URL))
            ->timeout($timeout ?? (int) config(self::CFG_TIMEOUT))
            ->withHeaders(['Authorization' => 'Key '.$this->apiKey()])
            ->acceptJson()
            ->asJson();
    }

    private function apiKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::FAL_API_KEY);
    }

    private function errorMessage(Response $response): string
    {
        $detail = $response->json('detail') ?? $response->json('error');

        if (is_string($detail)) {
            return $detail;
        }

        return $detail === null ? '' : (string) json_encode($detail);
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
        Log::info('fal.video', [
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskKey(),
        ]);
    }

    /** Mask the SAME key the request sends (DB value first, then config) so the log reflects reality. */
    private function maskKey(): string
    {
        $key = $this->apiKey();

        return $key === '' ? '' : substr($key, 0, self::KEY_VISIBLE_PREFIX).self::KEY_MASK;
    }
}
