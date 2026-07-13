<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Concerns\EncodesImageDataUris;
use App\Domain\Ai\Concerns\SignsKlingRequests;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * KlingVideoClient — the Kling async VIDEO adapter (task API).
 *
 * Mirrors FalVideoClient/AtlasCloudVideoClient (submit → id, poll → terminal state, bounded mp4
 * download, masked logging) on Kling's own shape:
 *   - submitTask() POSTs /v1/videos/image2video (an input frame) or /v1/videos/text2video (prompt
 *     only) and returns a COMPOSITE task id "{path}|{task_id}" — Kling's query route depends on
 *     WHICH endpoint created the task, and the VideoGenerationProvider contract passes back only
 *     the opaque task id;
 *   - pollTask() GETs {path}/{task_id} and maps data.task_result.videos[0].url onto the SAME
 *     normalized array the pollers already read for BytePlus/AtlasCloud/fal.
 *
 * The input frame is inlined as RAW base64 (Kling's "Base64 code" input — no data: prefix) because
 * the media disk may not be publicly reachable. A completed task carries what Kling REALLY billed
 * (billing[] = { charge_type: cash|unit, list_price, ... }), so the poll hands the caller that cost
 * under `cost.micro_usd` (KlingCost); the admin per-clip price is only the fallback for a
 * resource-package (unit) account. Storyboard clips NEVER charge.
 */
final class KlingVideoClient implements VideoGenerationProvider
{
    use EncodesImageDataUris, SignsKlingRequests;

    // === CONSTANTS ===
    private const TASK_ID_SEPARATOR = '|';

    // Kling's own request fields.
    private const FIELD_MODEL_NAME = 'model_name';

    private const FIELD_PROMPT = 'prompt';

    private const FIELD_IMAGE = 'image';

    private const FIELD_DURATION = 'duration';

    private const FIELD_ASPECT_RATIO = 'aspect_ratio';

    private const FIELD_MODE = 'mode';

    // Caller param keys (the shared VideoGenerationProvider vocabulary) → Kling's fields.
    private const PARAM_DURATION = 'duration_seconds';

    private const PARAM_RATIO = 'ratio';

    private const PARAM_MODE = 'mode';

    private const PARAM_RESOLUTION = 'resolution';

    // Kling clip lengths (whole seconds, sent as a STRING enum) — the allowed set is PER MODEL
    // LINE, not global. Verified against the live per-version schemas: v1 / v1-5 / v1-6 / v2 /
    // v2-1 / v2-5-turbo / v2-6 accept ONLY 5 or 10; the v3 line accepts every second from 3 to 15.
    // The default is therefore the narrow set that EVERY line accepts, and only a verified-extended
    // id widens it: guessing narrow merely rounds a clip up, while guessing wide is a hard 400
    // ("unknown enum") that breaks generation outright — the fabricated-Seedream-id scar.
    private const DURATIONS = [5, 10];

    private const DURATIONS_EXTENDED = [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];

    // Model ids starting with this take the extended set. Kling publishes no capability endpoint,
    // so a new line defaults to the safe set until its schema is verified.
    private const EXTENDED_DURATION_PREFIX = 'kling-v3';

    // Kling has no `resolution` field: the equivalent knob is `mode`. The shared resolution
    // vocabulary the other providers use maps onto it, and an explicit `mode` param overrides.
    private const MODES = ['std', 'pro', '4k'];

    private const RESOLUTION_MODES = [
        '480p' => 'std',
        '720p' => 'std',
        '1080p' => 'pro',
        '4k' => '4k',
    ];

    // aspect_ratio is a TEXT-TO-VIDEO field — image-to-video derives the ratio from the input
    // frame, so sending it there is at best ignored and at worst an unknown-enum rejection.
    private const RATIOS = ['16:9', '9:16', '1:1'];

    // Explicit per-call HTTP timeouts (seconds); a poll THEN a download fire together, so their
    // sum must stay UNDER the media-queue worker timeout (120s) — 30 + 60 = 90.
    private const POLL_TIMEOUT = 30;

    private const DOWNLOAD_TIMEOUT = 60;

    private const MAX_VIDEO_BYTES = 104_857_600; // 100 MiB result-download ceiling

    // Kling task statuses, normalized onto the shared poller vocabulary. Anything neither in-flight
    // nor 'succeed' is terminal failure (tolerates both 'failed' and 'fail').
    private const RAW_SUCCEED = 'succeed';

    private const RAW_IN_FLIGHT = ['submitted', 'processing'];

    private const STATUS_SUCCEEDED = 'succeeded';

    private const STATUS_FAILED = 'failed';

    private const STATUS_PROCESSING = 'processing';

    private const ENVELOPE_OK = 0;

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Submit a clip; returns the composite task id "{path}|{task_id}" (the query route depends on
     * which endpoint created the task). An input frame routes to image2video, else text2video.
     *
     * @param  array<int,string>  $imageUrls
     * @param  array<string,mixed>  $params  resolution / duration_seconds / ratio / mode
     */
    public function submitTask(string $model, string $prompt, array $imageUrls, array $params = [], ?string $baseUrl = null): string
    {
        $frame = $this->firstFrame($imageUrls);
        $path = KlingCatalog::videoPath($frame !== null);

        try {
            $response = $this->klingRequest(baseUrl: $baseUrl)->post($path, $this->body($model, $prompt, $frame, $params));
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('Kling video submit timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        $decoded = $this->decoded($response);

        if (! $response->successful() || (int) ($decoded['code'] ?? self::ENVELOPE_OK) !== self::ENVELOPE_OK) {
            $this->log($model, 'submit_error', $response->status());

            throw OpenRouterException::make(
                KlingErrorCodes::classify($response->status(), $decoded['code'] ?? null),
                sprintf(
                    'Kling video submit error (HTTP %d / code %s) for model %s: %s',
                    $response->status(),
                    (string) ($decoded['code'] ?? ''),
                    $model,
                    (string) ($decoded['message'] ?? ''),
                ),
                modelUsed: $model,
                providerStatus: $response->status(),
            );
        }

        $taskId = data_get($decoded, 'data.task_id');

        if (! is_string($taskId) || $taskId === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'Kling video submit returned no task id.',
                modelUsed: $model,
            );
        }

        $this->log($model, 'submitted', $response->status());

        return $path.self::TASK_ID_SEPARATOR.$taskId;
    }

    /**
     * Poll a composite task id and return the NORMALIZED task array every poller reads: status
     * (succeeded|failed|processing), content.video_url, error.message. Never throws for a terminal
     * FAILED task; throws only on transport/HTTP errors so the caller can reschedule the poll.
     *
     * @return array<string,mixed>
     */
    public function pollTask(string $taskId, ?string $baseUrl = null): array
    {
        [$path, $id] = $this->splitTaskId($taskId);

        try {
            $response = $this->klingRequest(self::POLL_TIMEOUT, $baseUrl)->get($path.'/'.$id);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                'Kling video poll timed out.',
                previous: $e,
            );
        }

        $decoded = $this->decoded($response);

        if (! $response->successful() || (int) ($decoded['code'] ?? self::ENVELOPE_OK) !== self::ENVELOPE_OK) {
            throw OpenRouterException::make(
                KlingErrorCodes::classify($response->status(), $decoded['code'] ?? null),
                sprintf('Kling video poll error (HTTP %d): %s', $response->status(), (string) ($decoded['message'] ?? '')),
                providerStatus: $response->status(),
            );
        }

        $status = strtolower((string) data_get($decoded, 'data.task_status'));

        if (in_array($status, self::RAW_IN_FLIGHT, true)) {
            return $this->normalized(self::STATUS_PROCESSING, null, null);
        }

        if ($status !== self::RAW_SUCCEED) {
            return $this->normalized(
                self::STATUS_FAILED,
                null,
                (string) (data_get($decoded, 'data.task_status_msg') ?: 'Kling reported status "'.$status.'".'),
            );
        }

        $videoUrl = data_get($decoded, 'data.task_result.videos.0.url');

        if (! is_string($videoUrl) || $videoUrl === '') {
            return $this->normalized(self::STATUS_FAILED, null, 'Kling returned no video url.');
        }

        // The completed task carries Kling's REAL cash price — hand it to the caller, which prefers
        // it over the admin hint (the hint is only the estimate).
        return $this->normalized(self::STATUS_SUCCEEDED, $videoUrl, null, KlingCost::videoMicroUsd($decoded));
    }

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
     * The Kling request body. Every knob is CLAMPED to a value Kling documents (it rejects an
     * unknown enum outright): the duration to its allowed seconds, the mode to std|pro|4k (mapped
     * from the shared `resolution` vocabulary unless an explicit `mode` overrides), and the aspect
     * ratio only on TEXT-to-video — an image-to-video clip takes its ratio from the input frame.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function body(string $model, string $prompt, ?string $frame, array $params): array
    {
        $body = [
            self::FIELD_MODEL_NAME => $model,
            self::FIELD_PROMPT => $prompt,
        ];

        if ($frame !== null) {
            $body[self::FIELD_IMAGE] = $frame;
        }

        // Kling takes the duration as a STRING enum of whole seconds, and which seconds are legal
        // depends on the model line.
        $body[self::FIELD_DURATION] = (string) $this->nearestDuration($params[self::PARAM_DURATION] ?? null, $model);

        // Text-to-video only (no input frame). 'adaptive' is OUR sentinel, not a Kling enum.
        if ($frame === null) {
            $ratio = (string) ($params[self::PARAM_RATIO] ?? '');
            if (in_array($ratio, self::RATIOS, true)) {
                $body[self::FIELD_ASPECT_RATIO] = $ratio;
            }
        }

        $mode = $this->mode($params);
        if ($mode !== null) {
            $body[self::FIELD_MODE] = $mode;
        }

        return $body;
    }

    /**
     * Kling's quality tier: an explicit `mode` param wins, else the shared `resolution` vocabulary
     * maps onto it. An unrecognised value is DROPPED (never forwarded) — Kling rejects an unknown
     * enum rather than ignoring it.
     *
     * @param  array<string,mixed>  $params
     */
    private function mode(array $params): ?string
    {
        $mode = strtolower(trim((string) ($params[self::PARAM_MODE] ?? '')));

        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        $resolution = strtolower(trim((string) ($params[self::PARAM_RESOLUTION] ?? '')));

        return self::RESOLUTION_MODES[$resolution] ?? null;
    }

    /**
     * The clip length this MODEL allows, closest to — and never ABOVE — the requested seconds
     * (video is billed per second, so rounding up would silently over-charge). A request below the
     * model's shortest allowed length takes that shortest length.
     */
    private function nearestDuration(mixed $requested, string $model): int
    {
        $durations = $this->allowedDurations($model);
        $seconds = is_numeric($requested) ? (int) $requested : min($durations);

        $allowed = array_filter($durations, static fn (int $d): bool => $d <= $seconds);

        return $allowed !== [] ? max($allowed) : min($durations);
    }

    /** The verified duration enum for a model line. @return array<int,int> */
    private function allowedDurations(string $model): array
    {
        return str_starts_with(strtolower(trim($model)), self::EXTENDED_DURATION_PREFIX)
            ? self::DURATIONS_EXTENDED
            : self::DURATIONS;
    }

    /** The first usable input frame as RAW base64 (Kling takes no data: prefix). @param array<int,string> $urls */
    private function firstFrame(array $urls): ?string
    {
        foreach (array_values($urls) as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $dataUri = $this->asDataUri($url);
            if ($dataUri === null) {
                continue;
            }

            $comma = strpos($dataUri, ',');

            return $comma === false ? $dataUri : substr($dataUri, $comma + 1);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function normalized(string $status, ?string $videoUrl, ?string $error, ?int $costMicroUsd = null): array
    {
        return [
            'status' => $status,
            'content' => ['video_url' => $videoUrl],
            'error' => ['message' => $error],
            'created_at' => 0,
            'updated_at' => 0,
            VideoGenerationProvider::KEY_COST => [
                VideoGenerationProvider::KEY_COST_MICRO_USD => $costMicroUsd,
            ],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function splitTaskId(string $taskId): array
    {
        $separator = strrpos($taskId, self::TASK_ID_SEPARATOR);

        if ($separator === false) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'Kling video task id is missing its endpoint segment.',
            );
        }

        return [substr($taskId, 0, $separator), substr($taskId, $separator + 1)];
    }

    /** @return array<string,mixed> */
    private function decoded(Response $response): array
    {
        return is_array($response->json()) ? $response->json() : [];
    }

    private function log(string $model, string $outcome, int $status): void
    {
        Log::info('kling.video', [
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskedCredential(),
        ]);
    }
}
