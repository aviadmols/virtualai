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
 * BytePlusVideoClient — the BytePlus / ModelArk async VIDEO adapter (the Seedance task API).
 *
 * Video generation is ASYNC and two-step, so this does NOT implement the (synchronous)
 * ImageGenerationProvider contract:
 *   - submitTask()  POSTs /contents/generations/tasks (a text part + optional first_frame/reference
 *     image parts) and returns the task id;
 *   - pollTask()    GETs the task until a terminal status; the MP4 lives at content.video_url (a
 *     signed CDN url, downloaded server-side like the image client's signed-url path).
 *
 * Auth + host mirror BytePlusImageClient (bearer key, ark region base_url; a per-model region host
 * may be passed in). NO USD cost is returned (only usage tokens) — the caller applies the admin
 * per-video flat-rate price. Failures classify into the shared OpenRouterException codes.
 */
final class BytePlusVideoClient implements VideoGenerationProvider
{
    use EncodesImageDataUris;

    // === CONSTANTS ===
    private const TASKS_PATH = '/contents/generations/tasks';

    private const CFG_BASE_URL = 'services.byteplus.base_url';
    private const CFG_TIMEOUT = 'services.byteplus.timeout';

    // Explicit per-call HTTP timeouts (seconds). The poller does a poll THEN a download in one
    // firing, so their sum must stay UNDER the media-queue worker timeout (120s) — 30 + 60 = 90.
    private const POLL_TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 60;

    // Only these video knobs are sent (unknown keys risk a 400). Defaults are broadly supported.
    private const DEFAULT_RESOLUTION = '720p';
    private const DEFAULT_DURATION = 5;
    private const ROLE_FIRST_FRAME = 'first_frame';
    private const ROLE_REFERENCE = 'reference_image';

    private const MAX_VIDEO_BYTES = 104_857_600; // 100 MiB result-download ceiling

    private const STATUS_SUCCEEDED = 'succeeded';
    // TERMINAL_FAILURE (the terminal non-success states) is inherited from VideoGenerationProvider,
    // the shared contract, so BytePlusVideoClient::TERMINAL_FAILURE still resolves to it.

    private const STATUS_RATE_LIMITED = 429;
    private const STATUS_SERVER_MIN = 500;

    private const KEY_VISIBLE_PREFIX = 8;
    private const KEY_MASK = '****';

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Submit a video generation task; returns the task id. $imageUrls are optional input frames
     * (the first becomes the first_frame, the rest reference images) for image-to-video.
     *
     * @param  array<int,string>  $imageUrls
     * @param  array<string,mixed>  $params   resolution / duration_seconds / ratio
     */
    public function submitTask(string $model, string $prompt, array $imageUrls, array $params = [], ?string $baseUrl = null): string
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        // Inline every input frame as a base64 data URI: ModelArk fetches url inputs ITSELF,
        // and a signed/expiring media url it cannot reach fails the task silently server-side.
        // Inlining kills that failure class (mirrors the AtlasCloud client).
        $inlined = $this->asDataUris($imageUrls);

        // An input that could not be inlined is DROPPED by the trait. Submitting anyway would
        // silently downgrade a paid image-to-video into text-to-video and store a clip that has
        // nothing to do with the frame — fail loudly instead.
        if ($imageUrls !== [] && $inlined === []) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                sprintf('BytePlus video submit could not read any input frame for model %s.', $model),
                modelUsed: $model,
            );
        }

        foreach ($inlined as $i => $url) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $url],
                'role' => $i === 0 ? self::ROLE_FIRST_FRAME : self::ROLE_REFERENCE,
            ];
        }

        $body = [
            'model' => $model,
            'content' => $content,
            'resolution' => (string) ($params['resolution'] ?? self::DEFAULT_RESOLUTION),
            'duration' => (int) ($params['duration_seconds'] ?? self::DEFAULT_DURATION),
            'watermark' => false,
        ];

        if (! empty($params['ratio'])) {
            $body['ratio'] = (string) $params['ratio'];
        }

        try {
            $response = $this->request($baseUrl)->post(self::TASKS_PATH, $body);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('BytePlus video submit timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        if (! $response->successful()) {
            $this->log($model, 'submit_error', $response->status());

            throw OpenRouterException::make(
                $this->classify($response->status()),
                sprintf('BytePlus video submit error (%d) for model %s: %s', $response->status(), $model, $this->errorMessage($response)),
                modelUsed: $model,
                providerStatus: $response->status(),
            );
        }

        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'BytePlus video submit returned no task id.',
                modelUsed: $model,
            );
        }

        $this->log($model, 'submitted', $response->status());

        return $id;
    }

    /**
     * Poll a task. Returns the decoded task array (status, content.video_url, usage, created_at,
     * updated_at, error). Never throws for a terminal FAILED task (that is a result); throws only
     * on transport/HTTP errors so the caller can reschedule the poll.
     *
     * @return array<string,mixed>
     */
    public function pollTask(string $taskId, ?string $baseUrl = null): array
    {
        try {
            $response = $this->request($baseUrl, self::POLL_TIMEOUT)->get(self::TASKS_PATH.'/'.$taskId);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                'BytePlus video poll timed out.',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw OpenRouterException::make(
                $this->classify($response->status()),
                sprintf('BytePlus video poll error (%d).', $response->status()),
                providerStatus: $response->status(),
            );
        }

        return is_array($response->json()) ? $response->json() : [];
    }

    /** True when a polled task has completed successfully. */
    public function succeeded(array $task): bool
    {
        return ($task['status'] ?? '') === self::STATUS_SUCCEEDED;
    }

    /** Download the result MP4 bytes from the (signed) video url, bounded. Null if unusable. */
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

    private function request(?string $baseUrl, ?int $timeout = null): PendingRequest
    {
        return $this->http
            ->baseUrl($baseUrl !== null && $baseUrl !== '' ? $baseUrl : (string) config(self::CFG_BASE_URL))
            ->timeout($timeout ?? (int) config(self::CFG_TIMEOUT))
            ->withToken((string) app(PlatformSettings::class)->resolve(PlatformSettings::BYTEPLUS_API_KEY))
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        $error = $response->json('error');

        return is_array($error) ? (string) ($error['message'] ?? '') : (string) $response->body();
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
        Log::info('byteplus.video', [
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskKey(),
        ]);
    }

    /** Mask the SAME key the request sends (DB value first, then config) so the log reflects reality. */
    private function maskKey(): string
    {
        $key = (string) app(PlatformSettings::class)->resolve(PlatformSettings::BYTEPLUS_API_KEY);

        return $key === '' ? '' : substr($key, 0, self::KEY_VISIBLE_PREFIX).self::KEY_MASK;
    }
}
