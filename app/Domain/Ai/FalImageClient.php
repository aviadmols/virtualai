<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Concerns\EncodesImageDataUris;
use App\Domain\Ai\Contracts\AsyncImageGenerationProvider;
use App\Domain\Credits\CreditMath;
use App\Domain\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * FalImageClient — the fal.ai adapter for image generation (queue API).
 *
 * fal runs every model behind one async queue: POST https://queue.fal.run/{model} returns a
 * request_id; the status endpoint moves IN_QUEUE → IN_PROGRESS → COMPLETED; the result endpoint
 * returns the model-specific output ({images: [{url,...}]} for image models). This client hides
 * the whole submit→poll→result dance behind the synchronous ImageGenerationProvider contract,
 * with a bounded poll budget sized for a queued worker.
 *
 * Auth is `Authorization: Key <FAL_KEY>` (fal's own scheme, NOT a Bearer). Input images: any
 * http(s) url in image_url/image_urls is inlined as a base64 data URI (fal documents data-URI
 * inputs) because the media disk may not be publicly reachable. Extra input keys a specific
 * model does not declare (e.g. image_urls on a text-to-image model) are ignored by fal's
 * validation, so one generic body serves the whole catalog.
 *
 * MONEY-SAFETY: fal returns NO per-request USD cost — parseCost uses the admin-entered per-image
 * price (the AiModel cost hint) as the authoritative flat rate, and returns UNAVAILABLE (never
 * charges) when no price is set.
 *
 * It ALSO implements the AsyncImageGenerationProvider seam (submitAsync/pollAsync), which
 * exposes that same queue as SEPARATE steps for the bulk product-image pipeline: submit once,
 * persist the request id, then poll in short ticks from a re-dispatching job. The synchronous
 * contract above is unchanged (the shopper-facing try-on/banner paths still block).
 *
 * NOTE (verified 2026-07-13 against fal's queue docs): fal documents NO request-level
 * idempotency key. Our deterministic asset key is therefore threaded through for correlation
 * only, and the guarantee that one asset is submitted exactly once is enforced on OUR side
 * (ShouldBeUnique on that key + a row-locked asset that never re-submits once it carries a
 * provider_request_id).
 */
final class FalImageClient implements AsyncImageGenerationProvider
{
    use EncodesImageDataUris;

    // === CONSTANTS ===
    private const REQUESTS_SEGMENT = '/requests/';

    private const STATUS_SUFFIX = '/status';

    // The result lives at .../requests/{id}/response (the submit reply's response_url shape); some
    // deployments still serve the bare .../requests/{id}, kept as a one-shot fallback.
    private const RESULT_SUFFIX = '/response';

    private const ROUTE_MISMATCH_STATUSES = [404, 405];

    private const CFG_KEY = 'services.fal.api_key';

    private const CFG_BASE_URL = 'services.fal.base_url';

    private const CFG_CATALOG_URL = 'services.fal.catalog_url';

    private const CFG_TIMEOUT = 'services.fal.timeout';

    // Queue statuses (fal's own vocabulary).
    private const STATUS_COMPLETED = 'COMPLETED';

    private const IN_FLIGHT = ['IN_QUEUE', 'IN_PROGRESS'];

    // Poll budget: 2s sleeps × 40 ≈ 80s of render time; each status GET is separately capped at
    // POLL_HTTP_TIMEOUT so a hung request cannot blow the queued worker's timeout.
    private const POLL_INTERVAL_MS = 2000;

    private const MAX_POLLS = 40;

    private const POLL_HTTP_TIMEOUT = 15;

    // Catalog categories that take NO input images — the image keys are stripped for them.
    private const TEXT_ONLY_CATEGORIES = ['text-to-image', 'text-to-video'];

    private const MAX_RETRIES = 1;

    private const BACKOFF_BASE_MS = 400;

    private const BACKOFF_JITTER_MS = 250;

    // Body keys that may carry input-image urls (converted to data URIs before submit).
    private const IMAGE_URL_KEY = 'image_url';

    private const IMAGE_URLS_KEY = 'image_urls';

    // A no-spend key probe: any authenticated GET on the queue host answers 401 for a bad key
    // and 4xx-not-401 for a good one. A zero request id never collides with a real run.
    private const PROBE_MODEL = 'fal-ai/flux/schnell';

    private const PROBE_REQUEST = '00000000-0000-0000-0000-000000000000';

    private const STATUS_UNAUTHORIZED = 401;

    private const STATUS_FORBIDDEN = 403;

    private const STATUS_RATE_LIMITED = 429;

    private const STATUS_SERVER_MIN = 500;

    private const KEY_VISIBLE_PREFIX = 8;

    private const KEY_MASK = '****';

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
            sprintf('No fal model produced a response for operation %s.', $operationKey),
        );
    }

    /** Submit to the queue, poll to completion, fetch the result. @return array<string,mixed> */
    private function generate(array $body, string $operationKey): array
    {
        // The model id is the URL path on fal, not a body field.
        $model = (string) ($body['model'] ?? '');
        unset($body['model']);

        $ticket = $this->submitTicket($model, $body, $operationKey);

        $this->awaitCompletion((string) $ticket->statusUrl, $model, $operationKey);

        $result = $this->fetchResult((string) $ticket->resultUrl, $this->requestBase($model, $ticket->requestId), $model, $operationKey);
        $result['request_id'] ??= $ticket->requestId;

        $this->logOutcome($operationKey, $model, 'ok', 200);

        return $result;
    }

    /**
     * ASYNC SEAM — submit ONE generation and hand back its ticket, without waiting. The bulk
     * product-image pipeline persists the ticket, so a later retry can only ever poll it.
     * $idempotencyKey is OUR asset key: fal documents no idempotency header, so it rides along
     * for log correlation only — the submit-once guarantee is enforced on our side.
     */
    public function submitAsync(string $operationKey, string $model, array $body, string $idempotencyKey): AsyncImageTicket
    {
        unset($body['model']); // the model is the URL path on fal, never a body field

        return $this->submitTicket($model, $body, $operationKey);
    }

    /**
     * ASYNC SEAM — ONE poll tick. A transport problem THROWS (the poller retries the poll, it
     * never re-submits); an upstream terminal-but-not-completed status is a typed failure.
     */
    public function pollAsync(AsyncImageTicket $ticket, string $operationKey): AsyncImagePoll
    {
        $requestBase = $this->requestBase($ticket->model, $ticket->requestId);
        $statusUrl = $ticket->statusUrl ?? $requestBase.self::STATUS_SUFFIX;

        try {
            $response = $this->request()->timeout(self::POLL_HTTP_TIMEOUT)->get($statusUrl);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('fal status poll timed out for model %s.', $ticket->model),
                modelUsed: $ticket->model,
                previous: $e,
            );
        }

        // A broken status route is a CLASSIFIED transport error — never mistaken for terminal.
        if (! $response->successful()) {
            $code = $this->classifyStatus($response->status());
            $this->logOutcome($operationKey, $ticket->model, 'status_'.$code, $response->status());

            throw OpenRouterException::make(
                $code,
                sprintf('fal status poll error (%d) for model %s.', $response->status(), $ticket->model),
                modelUsed: $ticket->model,
                providerStatus: $response->status(),
            );
        }

        $status = strtoupper((string) $response->json('status'));

        if (in_array($status, self::IN_FLIGHT, true)) {
            return AsyncImagePoll::pending();
        }

        // COMPLETED (or a terminal-but-not-completed status, whose detail lives on the result
        // endpoint): fetch the result. A provider-side failure surfaces as a typed failed poll.
        try {
            $result = $this->fetchResult(
                $ticket->resultUrl ?? $requestBase.self::RESULT_SUFFIX,
                $requestBase,
                $ticket->model,
                $operationKey,
            );
        } catch (OpenRouterException $e) {
            if ($status !== self::STATUS_COMPLETED) {
                return AsyncImagePoll::failed($e->getMessage()); // upstream said it is over, and it failed
            }

            throw $e; // COMPLETED but the result could not be fetched -> a transport blip; re-poll
        }

        $result['request_id'] ??= $ticket->requestId;

        return AsyncImagePoll::succeeded($result);
    }

    /**
     * Submit + build the ticket. The submit reply's status_url/response_url are AUTHORITATIVE
     * (fal's own routing for THIS request); the constructed paths are only the fallback.
     *
     * @param  array<string,mixed>  $body
     */
    private function submitTicket(string $model, array $body, string $operationKey): AsyncImageTicket
    {
        $submitted = $this->submit($model, $this->inlineInputImages($body, $model), $operationKey);
        $requestId = (string) $submitted['request_id'];
        $requestBase = $this->requestBase($model, $requestId);

        return new AsyncImageTicket(
            provider: self::PROVIDER_FAL,
            model: $model,
            requestId: $requestId,
            statusUrl: is_string($submitted['status_url'] ?? null) && $submitted['status_url'] !== ''
                ? $submitted['status_url']
                : $requestBase.self::STATUS_SUFFIX,
            resultUrl: is_string($submitted['response_url'] ?? null) && $submitted['response_url'] !== ''
                ? $submitted['response_url']
                : $requestBase.self::RESULT_SUFFIX,
        );
    }

    /** The per-request url base: /{model}/requests/{request_id}. */
    private function requestBase(string $model, string $requestId): string
    {
        return '/'.$model.self::REQUESTS_SEGMENT.$requestId;
    }

    /** @return array<string,mixed> the submit reply (request_id + status_url/response_url when given) */
    private function submit(string $model, array $body, string $operationKey): array
    {
        try {
            $response = $this->request()->post('/'.$model, $body);
        } catch (ConnectionException $e) {
            $this->logOutcome($operationKey, $model, 'timeout', null);

            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('fal submit timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        if (! $response->successful()) {
            $code = $this->classifyStatus($response->status());
            $this->logOutcome($operationKey, $model, $code, $response->status());

            throw OpenRouterException::make(
                $code,
                sprintf('fal submit error (%d) for model %s: %s', $response->status(), $model, $this->errorDetail($response->json())),
                modelUsed: $model,
                providerStatus: $response->status(),
            );
        }

        $decoded = is_array($response->json()) ? $response->json() : [];
        $id = $decoded['request_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                sprintf('fal submit returned no request id for model %s.', $model),
                modelUsed: $model,
            );
        }

        return $decoded;
    }

    /** Poll the queue status url until COMPLETED, bounded. */
    private function awaitCompletion(string $statusUrl, string $model, string $operationKey): void
    {
        for ($poll = 0; $poll < self::MAX_POLLS; $poll++) {
            try {
                $response = $this->request()->timeout(self::POLL_HTTP_TIMEOUT)->get($statusUrl);
            } catch (ConnectionException $e) {
                throw OpenRouterException::make(
                    OpenRouterException::CODE_MODEL_TIMEOUT,
                    sprintf('fal status poll timed out for model %s.', $model),
                    modelUsed: $model,
                    previous: $e,
                );
            }

            // A broken status route must be a CLASSIFIED error, never mistaken for terminal.
            if (! $response->successful()) {
                $code = $this->classifyStatus($response->status());
                $this->logOutcome($operationKey, $model, 'status_'.$code, $response->status());

                throw OpenRouterException::make(
                    $code,
                    sprintf('fal status poll error (%d) for model %s.', $response->status(), $model),
                    modelUsed: $model,
                    providerStatus: $response->status(),
                );
            }

            $status = strtoupper((string) $response->json('status'));

            if ($status === self::STATUS_COMPLETED) {
                return;
            }

            if (! in_array($status, self::IN_FLIGHT, true)) {
                // Terminal-but-not-completed: the result endpoint carries the error detail.
                return;
            }

            Sleep::usleep(self::POLL_INTERVAL_MS * 1000);
        }

        $this->logOutcome($operationKey, $model, 'poll_exhausted', null);

        throw OpenRouterException::make(
            OpenRouterException::CODE_MODEL_TIMEOUT,
            sprintf('fal generation did not complete in time for model %s.', $model),
            modelUsed: $model,
        );
    }

    /**
     * Fetch the result from the response url; a 404/405 route mismatch retries ONCE on the bare
     * request url (older deployments serve the result there).
     *
     * @return array<string,mixed>
     */
    private function fetchResult(string $resultUrl, string $fallbackUrl, string $model, string $operationKey): array
    {
        $response = $this->getResult($resultUrl, $model);

        if (in_array($response->status(), self::ROUTE_MISMATCH_STATUSES, true) && $fallbackUrl !== $resultUrl) {
            $response = $this->getResult($fallbackUrl, $model);
        }

        $decoded = is_array($response->json()) ? $response->json() : [];

        if (! $response->successful() || isset($decoded['detail']) || isset($decoded['error'])) {
            $code = $this->classifyStatus($response->status());
            $this->logOutcome($operationKey, $model, $code, $response->status());

            throw OpenRouterException::make(
                $code,
                sprintf('fal error (%d) for model %s: %s', $response->status(), $model, $this->errorDetail($decoded)),
                modelUsed: $model,
                providerStatus: $response->status(),
            );
        }

        return $decoded;
    }

    private function getResult(string $url, string $model): Response
    {
        try {
            return $this->request()->get($url);
        } catch (ConnectionException $e) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('fal result fetch timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }
    }

    public function extractModelUsed(array $response, string $requested): string
    {
        return $requested; // fal's result body does not echo the model; the path IS the model.
    }

    public function extractGenerationId(array $response): ?string
    {
        $id = $response['request_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function parseCost(array $response, ?int $estimatedCostMicroUsd = null): ParsedCost
    {
        // Flat-rate provider: the admin-entered per-image price is the authoritative cost.
        // Fail closed (unavailable -> never charged) when no positive price is configured.
        if ($estimatedCostMicroUsd !== null && $estimatedCostMicroUsd > 0) {
            return ParsedCost::fromEndpoint(CreditMath::microToUsd($estimatedCostMicroUsd));
        }

        return ParsedCost::unavailable($estimatedCostMicroUsd);
    }

    /**
     * Result image bytes + mime: images[0].url (image.url fallback) — an http url is downloaded,
     * a data URI is decoded in place. Returns [null, ''] when none is usable.
     *
     * @return array{0: string|null, 1: string}
     */
    public function extractImage(array $response): array
    {
        $item = $response['images'][0] ?? $response['image'] ?? null;
        $url = is_array($item) ? ($item['url'] ?? null) : null;

        if (! is_string($url) || $url === '') {
            return [null, ''];
        }

        if (str_starts_with($url, 'data:')) {
            return $this->decodeDataUri($url, is_array($item) ? $item : []);
        }

        try {
            $img = $this->http->timeout((int) config(self::CFG_TIMEOUT))->get($url);
            if ($img->successful()) {
                return [$img->body(), $img->header('Content-Type') ?: 'image/png'];
            }
        } catch (ConnectionException) {
            // fall through to [null, '']
        }

        return [null, ''];
    }

    public function checkConnection(?string $overrideKey = null): array
    {
        $key = $overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey();

        if ($key === '' || PlatformSettings::looksLikePlaceholder($key)) {
            return ['ok' => false, 'reason' => 'not_configured', 'message' => 'No fal.ai API key is set.', 'detail' => null];
        }

        try {
            $response = $this->request($key)
                ->get('/'.self::PROBE_MODEL.self::REQUESTS_SEGMENT.self::PROBE_REQUEST.self::STATUS_SUFFIX);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'reason' => 'timeout', 'message' => 'Could not reach fal.ai (check the network).', 'detail' => $e->getMessage()];
        }

        if ($response->status() === self::STATUS_UNAUTHORIZED || $response->status() === self::STATUS_FORBIDDEN) {
            return ['ok' => false, 'reason' => 'invalid_key', 'message' => 'fal.ai rejected the key ('.$response->status().' — invalid or revoked).', 'detail' => 'HTTP '.$response->status()];
        }

        // Any authenticated answer (200, or a 4xx for the nonexistent probe request) proves the key.
        if ($response->status() < self::STATUS_SERVER_MIN) {
            return ['ok' => true, 'reason' => 'ok', 'message' => 'fal.ai accepted the API key.', 'detail' => null];
        }

        return ['ok' => false, 'reason' => 'error', 'message' => 'fal.ai returned an error ('.$response->status().').', 'detail' => 'HTTP '.$response->status()];
    }

    public function checkModel(string $modelId, ?string $overrideKey = null, ?string $baseUrl = null): array
    {
        // The key first (a catalog hit is useless with a dead key). $baseUrl is ignored — fal is a
        // single global queue host.
        $key = $this->checkConnection($overrideKey);
        if (! $key['ok']) {
            return $key;
        }

        // The PUBLIC catalog answers model existence without spending a generation.
        try {
            $response = $this->http
                ->baseUrl((string) config(self::CFG_CATALOG_URL))
                ->timeout((int) config(self::CFG_TIMEOUT))
                ->acceptJson()
                ->get('/models', ['keywords' => $modelId]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'reason' => 'timeout', 'message' => 'Could not reach the fal.ai model catalog.', 'detail' => $e->getMessage()];
        }

        $items = is_array($response->json('items')) ? $response->json('items') : [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $modelId) {
                return ['ok' => true, 'reason' => 'ok', 'message' => 'fal.ai model "'.$modelId.'" exists in the catalog.', 'detail' => null];
            }
        }

        return ['ok' => false, 'reason' => 'model_not_found', 'message' => 'fal.ai model "'.$modelId.'" was not found in the catalog.', 'detail' => 'HTTP '.$response->status()];
    }

    /**
     * Inline every input-image url (image_url / image_urls) as a base64 data URI so fal can always
     * read it — the media disk may not be publicly reachable. Unusable urls are dropped; empty
     * keys are removed entirely. A model the PUBLIC catalog knows as text-to-image/text-to-video
     * takes no input images at all, so the keys are stripped (no wasted downloads, no 422 risk);
     * an unknown/unreachable catalog keeps them (fal ignores undeclared fields).
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function inlineInputImages(array $body, string $model): array
    {
        $hasImages = is_string($body[self::IMAGE_URL_KEY] ?? null) || is_array($body[self::IMAGE_URLS_KEY] ?? null);
        if (! $hasImages) {
            return $body;
        }

        if (in_array(app(FalModelCatalog::class)->categoryOf($model), self::TEXT_ONLY_CATEGORIES, true)) {
            unset($body[self::IMAGE_URL_KEY], $body[self::IMAGE_URLS_KEY]);

            return $body;
        }

        if (is_string($body[self::IMAGE_URL_KEY] ?? null)) {
            $single = $this->asDataUri($body[self::IMAGE_URL_KEY]);
            if ($single !== null) {
                $body[self::IMAGE_URL_KEY] = $single;
            } else {
                unset($body[self::IMAGE_URL_KEY]);
            }
        }

        if (is_array($body[self::IMAGE_URLS_KEY] ?? null)) {
            $list = $this->asDataUris($body[self::IMAGE_URLS_KEY]);
            if ($list !== []) {
                $body[self::IMAGE_URLS_KEY] = $list;
            } else {
                unset($body[self::IMAGE_URLS_KEY]);
            }
        }

        return $body;
    }

    /** @return array{0: string|null, 1: string} */
    private function decodeDataUri(string $uri, array $item): array
    {
        $comma = strpos($uri, ',');
        if ($comma === false) {
            return [null, ''];
        }

        $bytes = base64_decode(substr($uri, $comma + 1), true);
        if ($bytes === false || $bytes === '') {
            return [null, ''];
        }

        $mime = is_string($item['content_type'] ?? null) && $item['content_type'] !== ''
            ? $item['content_type']
            : 'image/png';

        return [$bytes, $mime];
    }

    private function request(?string $overrideKey = null): PendingRequest
    {
        // fal's auth scheme is `Authorization: Key <FAL_KEY>` — NOT a Bearer token.
        return $this->http
            ->baseUrl((string) config(self::CFG_BASE_URL))
            ->timeout((int) config(self::CFG_TIMEOUT))
            ->withHeaders(['Authorization' => 'Key '.($overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey())])
            ->acceptJson()
            ->asJson();
    }

    /** The fal key: the Settings-page value (DB, encrypted) if set, else the env var. */
    private function apiKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::FAL_API_KEY);
    }

    private function errorDetail(mixed $decoded): string
    {
        if (! is_array($decoded)) {
            return '';
        }

        $detail = $decoded['detail'] ?? $decoded['error'] ?? '';

        return is_string($detail) ? $detail : (string) json_encode($detail);
    }

    private function classifyStatus(int $status): string
    {
        if ($status === self::STATUS_RATE_LIMITED) {
            return OpenRouterException::CODE_RATE_LIMITED;
        }

        if ($status >= self::STATUS_SERVER_MIN) {
            return OpenRouterException::CODE_PROVIDER_OUTAGE;
        }

        return OpenRouterException::CODE_BAD_REQUEST;
    }

    private function sleepBackoff(int $attempt): void
    {
        $ms = self::BACKOFF_BASE_MS * (2 ** $attempt) + random_int(0, self::BACKOFF_JITTER_MS);
        Sleep::usleep($ms * 1000);
    }

    private function logOutcome(string $operationKey, string $model, string $outcome, ?int $status): void
    {
        Log::info('fal.call', [
            'operation' => $operationKey,
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
