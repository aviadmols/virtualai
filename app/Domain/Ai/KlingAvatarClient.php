<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Concerns\SignsKlingRequests;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * KlingAvatarClient — Kling's native talking-AVATAR adapter (image + audio → lip-synced video).
 *
 * The task API POST /v1/videos/avatar/image2video takes a reference IMAGE + an AUDIO track and
 * renders a video of the avatar speaking that audio. It has NO model_name (a dedicated endpoint);
 * the only knob is `mode` (std|pro). It reuses the SAME async submit→poll shape + Kling auth as
 * KlingVideoClient, so it implements VideoGenerationProvider and slots straight into the Playground
 * poller. The video contract's submitTask carries no audio, so the audio url rides in
 * $params['audio_url'] (the caller signs the stored image + audio).
 *
 * `image` + `sound_file` are passed as accessible URLs (Kling's documented happy path). A completed
 * task carries Kling's real price (billing[]) via KlingCost; the admin hint is the fallback.
 */
final class KlingAvatarClient implements VideoGenerationProvider
{
    use SignsKlingRequests;

    // === CONSTANTS ===
    // The native Kling AI-Avatar task endpoint (create + query {id}).
    public const PATH = '/v1/videos/avatar/image2video';

    // The audio url + mode ride through $params (the video contract's submitTask has no audio arg).
    public const PARAM_AUDIO_URL = 'audio_url';

    public const PARAM_MODE = 'mode';

    // Kling avatar request fields.
    private const FIELD_IMAGE = 'image';

    private const FIELD_SOUND_FILE = 'sound_file';

    private const FIELD_PROMPT = 'prompt';

    private const FIELD_MODE = 'mode';

    // The documented modes + the prompt cap (Kling rejects an unknown enum / an over-long prompt).
    private const MODES = ['std', 'pro'];

    private const DEFAULT_MODE = 'std';

    private const PROMPT_MAX = 2500;

    private const POLL_TIMEOUT = 30;

    private const DOWNLOAD_TIMEOUT = 60;

    private const MAX_VIDEO_BYTES = 104_857_600; // 100 MiB result-download ceiling

    // Kling task statuses, normalized onto the shared poller vocabulary.
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
     * Submit an avatar task. $imageUrls[0] is the reference image; $params['audio_url'] the audio;
     * $params['mode'] the quality tier. $model is unused (the endpoint has no model_name). Returns
     * the Kling task id.
     *
     * @param  array<int,string>  $imageUrls
     * @param  array<string,mixed>  $params
     */
    public function submitTask(string $model, string $prompt, array $imageUrls, array $params = [], ?string $baseUrl = null): string
    {
        $image = $this->firstUrl($imageUrls);
        $audio = trim((string) ($params[self::PARAM_AUDIO_URL] ?? ''));

        if ($image === null || $audio === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'Kling avatar needs both a reference image and an audio file.',
            );
        }

        try {
            $response = $this->klingRequest(baseUrl: $baseUrl)->post(self::PATH, $this->body($image, $audio, $prompt, $params));
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(OpenRouterException::CODE_MODEL_TIMEOUT, 'Kling avatar submit timed out.', previous: $e);
        }

        $decoded = $this->decoded($response);

        if (! $response->successful() || (int) ($decoded['code'] ?? self::ENVELOPE_OK) !== self::ENVELOPE_OK) {
            $this->log('submit_error', $response->status());

            throw OpenRouterException::make(
                KlingErrorCodes::classify($response->status(), $decoded['code'] ?? null),
                sprintf(
                    'Kling avatar submit error (HTTP %d / code %s): %s',
                    $response->status(),
                    (string) ($decoded['code'] ?? ''),
                    (string) ($decoded['message'] ?? ''),
                ),
                providerStatus: $response->status(),
            );
        }

        $taskId = data_get($decoded, 'data.task_id');

        if (! is_string($taskId) || $taskId === '') {
            throw OpenRouterException::make(OpenRouterException::CODE_BAD_REQUEST, 'Kling avatar submit returned no task id.');
        }

        $this->log('submitted', $response->status());

        return $taskId;
    }

    /**
     * Poll the avatar task; returns the NORMALIZED task array (status, content.video_url, cost).
     * Never throws for a terminal FAILED task; throws only on transport/HTTP errors.
     *
     * @return array<string,mixed>
     */
    public function pollTask(string $taskId, ?string $baseUrl = null): array
    {
        try {
            $response = $this->klingRequest(self::POLL_TIMEOUT, $baseUrl)->get(self::PATH.'/'.$taskId);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(OpenRouterException::CODE_MODEL_TIMEOUT, 'Kling avatar poll timed out.', previous: $e);
        }

        $decoded = $this->decoded($response);

        if (! $response->successful() || (int) ($decoded['code'] ?? self::ENVELOPE_OK) !== self::ENVELOPE_OK) {
            throw OpenRouterException::make(
                KlingErrorCodes::classify($response->status(), $decoded['code'] ?? null),
                sprintf('Kling avatar poll error (HTTP %d): %s', $response->status(), (string) ($decoded['message'] ?? '')),
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
                (string) (data_get($decoded, 'data.task_status_msg') ?: 'Kling avatar reported status "'.$status.'".'),
            );
        }

        $videoUrl = data_get($decoded, 'data.task_result.videos.0.url');

        if (! is_string($videoUrl) || $videoUrl === '') {
            return $this->normalized(self::STATUS_FAILED, null, 'Kling avatar returned no video url.');
        }

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
     * The Kling avatar request body: the reference image + audio (as urls), an optional prompt
     * (clamped) and the mode (dropped to the default when not a documented value).
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function body(string $image, string $audio, string $prompt, array $params): array
    {
        $body = [
            self::FIELD_IMAGE => $image,
            self::FIELD_SOUND_FILE => $audio,
        ];

        $prompt = trim($prompt);
        if ($prompt !== '') {
            $body[self::FIELD_PROMPT] = mb_substr($prompt, 0, self::PROMPT_MAX);
        }

        $mode = strtolower(trim((string) ($params[self::PARAM_MODE] ?? '')));
        $body[self::FIELD_MODE] = in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;

        return $body;
    }

    /** The first usable http(s) url. @param  array<int,string>  $urls */
    private function firstUrl(array $urls): ?string
    {
        foreach (array_values($urls) as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
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

    /** @return array<string,mixed> */
    private function decoded(Response $response): array
    {
        return is_array($response->json()) ? $response->json() : [];
    }

    private function log(string $outcome, int $status): void
    {
        Log::info('kling.avatar', [
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskedCredential(),
        ]);
    }
}
