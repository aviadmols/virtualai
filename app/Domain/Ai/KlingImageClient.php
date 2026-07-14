<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Concerns\EncodesImageDataUris;
use App\Domain\Ai\Concerns\SignsKlingRequests;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Credits\CreditMath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * KlingImageClient — the Kling (Kuaishou) adapter for image generation + VIRTUAL TRY-ON.
 *
 * Kling is an async task API: POST the endpoint → {data:{task_id, task_status}}; GET
 * {endpoint}/{task_id} until task_status is terminal → data.task_result.images[].url. This client
 * hides the whole submit→poll→result dance behind the SYNCHRONOUS ImageGenerationProvider contract
 * (the FalImageClient shape), with a bounded poll budget sized for a queued worker.
 *
 * Two endpoints, routed by model id (KlingCatalog):
 *   kolors-virtual-try-on*  → /v1/images/kolors-virtual-try-on  (human_image + cloth_image)
 *   everything else         → /v1/images/generations            (prompt [+ image])
 *
 * Auth is the static API key sent verbatim, else a per-request HS256 JWT signed with the SECRET key
 * of the legacy pair (SignsKlingRequests). Input images are inlined as RAW base64 (Kling's
 * documented "Base64 code" input, with NO data: prefix) because the media disk may not be publicly
 * reachable.
 *
 * MONEY-SAFETY: Kling DOES return the real cost of a task (data.final_balance_deduction.list_price),
 * so that price IS the charge — the admin cost hint is only the reservation estimate. A response
 * with no parsable cash price (a resource-package account bills in units) falls back to the hint,
 * and with no hint either parseCost returns UNAVAILABLE — the money path fails closed (cancelled,
 * never charged at $0). See KlingCost.
 */
final class KlingImageClient implements ImageGenerationProvider
{
    use EncodesImageDataUris, SignsKlingRequests;

    // === CONSTANTS ===
    // The generic body keys the CALLERS speak; the client maps them onto Kling's own fields.
    // Anything else in the body passes through verbatim (native Kling params stay configurable).
    public const KEY_MODEL = 'model';

    public const KEY_PROMPT = 'prompt';

    public const KEY_IMAGE_URLS = 'image_urls';

    // Kling's own request fields.
    private const FIELD_MODEL_NAME = 'model_name';

    private const FIELD_NEGATIVE_PROMPT = 'negative_prompt';

    private const FIELD_IMAGE = 'image';

    private const FIELD_HUMAN_IMAGE = 'human_image';

    private const FIELD_CLOTH_IMAGE = 'cloth_image';

    // Kling task statuses (its own vocabulary). Anything not in-flight and not SUCCEED is terminal
    // failure — this tolerates both 'failed' and 'fail' without guessing which one is canonical.
    private const STATUS_SUCCEED = 'succeed';

    private const IN_FLIGHT = ['submitted', 'processing'];

    // Poll budget: 3s × 40 ≈ 120s of render time; each status GET is separately capped so a hung
    // request cannot blow the queued worker's timeout.
    private const POLL_INTERVAL_MS = 3000;

    private const MAX_POLLS = 40;

    private const POLL_HTTP_TIMEOUT = 20;

    private const MAX_RETRIES = 1;

    // Kling's own guidance for a concurrency rejection is an initial delay of at least 1s.
    private const BACKOFF_BASE_MS = 1000;

    private const BACKOFF_JITTER_MS = 250;

    private const ENVELOPE_OK = 0;

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function callWithFallback(
        string $operationKey,
        string $primaryModel,
        ?string $fallbackModel,
        callable $buildBody,
    ): array {
        $models = array_values(array_filter([$primaryModel, $fallbackModel]));
        $last = null;

        foreach ($models as $model) {
            $attempt = 0;

            while (true) {
                try {
                    return $this->generate($buildBody($model), $operationKey);
                } catch (OpenRouterException $e) {
                    $last = $e;

                    if ($e->isTransient() && $attempt < self::MAX_RETRIES) {
                        $this->sleepBackoff($attempt);
                        $attempt++;

                        continue;
                    }

                    if ($e->errorCode === OpenRouterException::CODE_BAD_REQUEST) {
                        throw $e;
                    }

                    break;
                }
            }
        }

        throw $last ?? OpenRouterException::make(
            OpenRouterException::CODE_PROVIDER_OUTAGE,
            sprintf('No Kling model produced a response for operation %s.', $operationKey),
        );
    }

    /** Submit the task, poll it to a terminal state, return the completed task envelope. @return array<string,mixed> */
    private function generate(array $body, string $operationKey): array
    {
        $model = trim((string) ($body[self::KEY_MODEL] ?? ''));
        $path = KlingCatalog::imagePath($model);

        $taskId = $this->submit($path, $this->klingBody($body, $model), $model, $operationKey);
        $task = $this->awaitCompletion($path, $taskId, $model, $operationKey);

        $this->logOutcome($operationKey, $model, 'ok', 200);

        return $task;
    }

    /**
     * Map the generic caller body onto Kling's own fields for the routed endpoint. Input images are
     * inlined as RAW base64. Unknown keys pass through verbatim, so a native Kling param the admin
     * configured on the operation (image_reference, image_fidelity, cfg_scale, …) still reaches it.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function klingBody(array $body, string $model): array
    {
        $urls = array_values(array_filter(
            is_array($body[self::KEY_IMAGE_URLS] ?? null) ? $body[self::KEY_IMAGE_URLS] : [],
            static fn ($u): bool => is_string($u) && $u !== '',
        ));

        unset($body[self::KEY_MODEL], $body[self::KEY_IMAGE_URLS]);

        $body[self::FIELD_MODEL_NAME] = $model;

        // Kling rejects a prompt/negative_prompt over its hard cap (400 / code 1201) — clamp.
        foreach ([self::KEY_PROMPT, self::FIELD_NEGATIVE_PROMPT] as $field) {
            if (is_string($body[$field] ?? null)) {
                $body[$field] = KlingCatalog::clampPrompt($body[$field]);
            }
        }

        if (KlingCatalog::isTryOn($model)) {
            // The dedicated try-on endpoint takes the two images and NO prompt.
            unset($body[self::KEY_PROMPT]);

            $human = isset($urls[0]) ? $this->asRawBase64($urls[0]) : null;
            $cloth = isset($urls[1]) ? $this->asRawBase64($urls[1]) : null;

            if ($human === null || $cloth === null) {
                throw OpenRouterException::make(
                    OpenRouterException::CODE_BAD_REQUEST,
                    sprintf('Kling virtual try-on needs BOTH a person image and a garment image (model %s).', $model),
                    modelUsed: $model,
                );
            }

            $body[self::FIELD_HUMAN_IMAGE] = $human;
            $body[self::FIELD_CLOTH_IMAGE] = $cloth;

            return $body;
        }

        // Image generation: the first input image (if any) is the reference.
        $reference = isset($urls[0]) ? $this->asRawBase64($urls[0]) : null;

        if ($reference !== null) {
            $body[self::FIELD_IMAGE] = $reference;
        }

        return $body;
    }

    /** POST the task; returns the task id. */
    private function submit(string $path, array $body, string $model, string $operationKey): string
    {
        try {
            $response = $this->klingRequest()->post($path, $body);
        } catch (ConnectionException $e) {
            $this->logOutcome($operationKey, $model, 'timeout', null);

            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('Kling submit timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        $decoded = $this->decodeOrFail($response, $model, $operationKey, 'submit');
        $taskId = data_get($decoded, 'data.task_id');

        if (! is_string($taskId) || $taskId === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                sprintf('Kling submit returned no task id for model %s.', $model),
                modelUsed: $model,
            );
        }

        return $taskId;
    }

    /** Poll {path}/{task_id} until the task is terminal. @return array<string,mixed> the completed envelope */
    private function awaitCompletion(string $path, string $taskId, string $model, string $operationKey): array
    {
        for ($poll = 0; $poll < self::MAX_POLLS; $poll++) {
            try {
                $response = $this->klingRequest(self::POLL_HTTP_TIMEOUT)->get($path.'/'.$taskId);
            } catch (ConnectionException $e) {
                throw OpenRouterException::make(
                    OpenRouterException::CODE_MODEL_TIMEOUT,
                    sprintf('Kling status poll timed out for model %s.', $model),
                    modelUsed: $model,
                    previous: $e,
                );
            }

            $decoded = $this->decodeOrFail($response, $model, $operationKey, 'poll');
            $status = strtolower((string) data_get($decoded, 'data.task_status'));

            if ($status === self::STATUS_SUCCEED) {
                return $decoded;
            }

            if (! in_array($status, self::IN_FLIGHT, true)) {
                // Terminal, not successful — Kling explains why in task_status_msg.
                $this->logOutcome($operationKey, $model, 'task_'.$status, null);

                throw OpenRouterException::make(
                    OpenRouterException::CODE_MODEL_REFUSED,
                    sprintf(
                        'Kling task %s for model %s: %s',
                        $status !== '' ? $status : 'failed',
                        $model,
                        (string) data_get($decoded, 'data.task_status_msg'),
                    ),
                    modelUsed: $model,
                );
            }

            Sleep::usleep(self::POLL_INTERVAL_MS * 1000);
        }

        $this->logOutcome($operationKey, $model, 'poll_exhausted', null);

        throw OpenRouterException::make(
            OpenRouterException::CODE_MODEL_TIMEOUT,
            sprintf('Kling generation did not complete in time for model %s.', $model),
            modelUsed: $model,
        );
    }

    /**
     * Decode a Kling reply, throwing a CLASSIFIED error on an HTTP failure OR a non-zero envelope
     * code (Kling can answer 200 with an error envelope).
     *
     * @return array<string,mixed>
     */
    private function decodeOrFail(Response $response, string $model, string $operationKey, string $stage): array
    {
        $decoded = is_array($response->json()) ? $response->json() : [];
        $envelopeCode = $decoded['code'] ?? self::ENVELOPE_OK;

        if ($response->successful() && (int) $envelopeCode === self::ENVELOPE_OK) {
            return $decoded;
        }

        $code = KlingErrorCodes::classify($response->status(), $envelopeCode);
        $this->logOutcome($operationKey, $model, $stage.'_'.$code, $response->status());

        throw OpenRouterException::make(
            $code,
            sprintf(
                'Kling %s error (HTTP %d / code %s) for model %s: %s',
                $stage,
                $response->status(),
                (string) $envelopeCode,
                $model,
                (string) ($decoded['message'] ?? ''),
            ),
            modelUsed: $model,
            providerStatus: $response->status(),
        );
    }

    public function extractModelUsed(array $response, string $requested): string
    {
        return $requested; // Kling's task envelope does not echo the model name.
    }

    public function extractGenerationId(array $response): ?string
    {
        $id = data_get($response, 'data.task_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The cost of the completed task, in order of truth:
     *   1. the REAL cash price Kling billed (data.final_balance_deduction.list_price) — the charge;
     *   2. else the admin per-image price hint (a resource-package account bills in units and
     *      returns no cash price at all);
     *   3. else UNAVAILABLE — never a guess, never a silent $0 (the money path fails closed).
     */
    public function parseCost(array $response, ?int $estimatedCostMicroUsd = null): ParsedCost
    {
        $listPrice = KlingCost::imageUsd($response);

        if ($listPrice !== null) {
            return ParsedCost::inline($listPrice);
        }

        if ($estimatedCostMicroUsd !== null && $estimatedCostMicroUsd > 0) {
            return ParsedCost::fromEndpoint(CreditMath::microToUsd($estimatedCostMicroUsd));
        }

        return ParsedCost::unavailable($estimatedCostMicroUsd);
    }

    /**
     * Result image bytes + mime: data.task_result.images[0].url, downloaded. Returns [null, '']
     * when none is usable.
     *
     * @return array{0: string|null, 1: string}
     */
    public function extractImage(array $response): array
    {
        $url = data_get($response, 'data.task_result.images.0.url');

        if (! is_string($url) || $url === '') {
            return [null, ''];
        }

        try {
            $image = $this->http->timeout($this->timeout())->get($url);
        } catch (ConnectionException) {
            return [null, ''];
        }

        if (! $image->successful()) {
            return [null, ''];
        }

        return [$image->body(), $image->header('Content-Type') ?: 'image/png'];
    }

    /**
     * Kling has no model-list endpoint, so a model id cannot be verified without spending a
     * generation. The honest answer is the KEY check plus that caveat — never a fake "model exists".
     */
    public function checkModel(string $modelId, ?string $overrideKey = null, ?string $baseUrl = null): array
    {
        $key = $this->checkConnection($overrideKey, $baseUrl);

        if (! $key['ok']) {
            return $key;
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'message' => 'Kling accepted the credentials. Kling publishes no model catalog, so "'.$modelId.'" can only be proven by a real generation (run it in the Playground).',
            'detail' => null,
        ];
    }

    /** An http(s) url downloaded and re-encoded as RAW base64 (Kling takes no data: prefix). */
    private function asRawBase64(string $url): ?string
    {
        $dataUri = $this->asDataUri($url);

        if ($dataUri === null) {
            return null;
        }

        $comma = strpos($dataUri, ',');

        // Already raw base64 (a non-http input passed through the trait unchanged).
        return $comma === false ? $dataUri : substr($dataUri, $comma + 1);
    }

    private function sleepBackoff(int $attempt): void
    {
        $ms = self::BACKOFF_BASE_MS * (2 ** $attempt) + random_int(0, self::BACKOFF_JITTER_MS);
        Sleep::usleep($ms * 1000);
    }

    private function logOutcome(string $operationKey, string $model, string $outcome, ?int $status): void
    {
        Log::info('kling.call', [
            'operation' => $operationKey,
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskedCredential(),
        ]);
    }
}
