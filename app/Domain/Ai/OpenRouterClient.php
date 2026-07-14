<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * OpenRouterClient — the thin, well-tested seam to OpenRouter.
 *
 * Single responsibility: build the request, send it, parse the response, mask the
 * logs. Knows NOTHING about credits, tenancy or the pipeline. It reads the
 * server-only key from config, sends the three required headers, honours the
 * timeout, masks every log line (bearer key + image payloads), and classifies
 * every failure into a typed OpenRouterException.
 *
 * The fallback is owned HERE (not provider-side) so classification is clean: a
 * primary failure is retried (bounded backoff on transient) then re-called on the
 * fallback model, and the terminal failure carries a stable error code.
 */
final class OpenRouterClient implements ImageGenerationProvider
{
    // === CONSTANTS ===
    private const CHAT_PATH = '/chat/completions';
    private const GENERATION_PATH = '/generation';
    private const KEY_PATH = '/key'; // GET /key — bearer info (label + usage/limit), no spend
    private const MODELS_PATH = '/models'; // GET /models — the model catalog, no spend
    private const DATA_URL_PREFIX = 'data:';

    // Required headers (OpenRouter attribution + auth).
    private const HEADER_REFERER = 'HTTP-Referer';
    private const HEADER_TITLE = 'X-Title';

    // Config keys (server-only key lives in config/services.php openrouter.*).
    private const CFG_KEY = 'services.openrouter.key';
    private const CFG_BASE_URL = 'services.openrouter.base_url';
    private const CFG_TIMEOUT = 'services.openrouter.timeout';
    private const CFG_REFERER = 'services.openrouter.http_referer';
    private const CFG_TITLE = 'services.openrouter.app_title';

    // Backoff / retry knobs. Bounded — a blind retry risks a double spend.
    private const MAX_RETRIES = 1;            // one bounded retry on the primary per model
    private const BACKOFF_BASE_MS = 400;      // exponential base
    private const BACKOFF_JITTER_MS = 250;    // +- jitter
    private const COST_LOOKUP_ATTEMPTS = 3;   // the generation endpoint can lag
    private const COST_LOOKUP_BACKOFF_MS = 300;

    // Masking — show only the prefix of the bearer key in logs.
    private const KEY_VISIBLE_PREFIX = 8;
    private const KEY_MASK = '****';

    // Retryable provider statuses.
    private const STATUS_RATE_LIMITED = 429;
    private const STATUS_SERVER_MIN = 500;

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * POST a chat/completions body and return the raw decoded response array.
     * Classifies HTTP-level failures; does NOT interpret the choices (the
     * operation callers do that). Single model id — fallback is orchestrated by
     * callWithFallback().
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function chat(array $body, string $operationKey): array
    {
        $model = (string) ($body['model'] ?? 'unknown');

        $this->assertKeyConfigured($operationKey, $model);

        try {
            $response = $this->request()->post(self::CHAT_PATH, $body);
        } catch (ConnectionException $e) {
            $this->logOutcome($operationKey, $model, 'timeout', null, null, null);

            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('OpenRouter call timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        return $this->handleResponse($response, $operationKey, $model);
    }

    /**
     * Test the bearer key WITHOUT spending: GET /key returns the key's label + usage/limit
     * for a valid key, 401 for an invalid one. Returns a typed result the Settings page
     * renders — it NEVER throws (a bad key / unreachable provider is a result, not a 500).
     * An optional $overrideKey lets the UI test a just-typed key before it is saved.
     *
     * @return array{ok: bool, reason: string, message: string, detail: ?string}
     */
    public function checkConnection(?string $overrideKey = null): array
    {
        $key = $overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey();

        if ($key === '' || PlatformSettings::looksLikePlaceholder($key)) {
            return ['ok' => false, 'reason' => 'not_configured', 'message' => 'No OpenRouter API key is set.', 'detail' => null];
        }

        try {
            $response = $this->request($key)->get(self::KEY_PATH);
        } catch (ConnectionException) {
            return ['ok' => false, 'reason' => 'timeout', 'message' => 'Could not reach OpenRouter.', 'detail' => null];
        }

        if ($response->successful()) {
            return ['ok' => true, 'reason' => 'ok', 'message' => 'Connected to OpenRouter.', 'detail' => $this->keySummary((array) ($response->json('data') ?? []))];
        }

        $detail = 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 2000);

        if ($response->status() === 401) {
            return ['ok' => false, 'reason' => 'invalid_key', 'message' => 'OpenRouter rejected the key (401 — invalid or revoked).', 'detail' => $detail];
        }

        return ['ok' => false, 'reason' => 'error', 'message' => 'OpenRouter returned an error ('.$response->status().').', 'detail' => $detail];
    }

    /**
     * Test a SPECIFIC OpenRouter model WITHOUT spending: GET /models lists the live catalog;
     * we check the id is present (a retired/mistyped id is the common failure). Never throws.
     * $baseUrl is ignored — OpenRouter is a single global endpoint (the param exists only to
     * satisfy the shared provider contract, where BytePlus uses a per-region host).
     *
     * @return array{ok: bool, reason: string, message: string, detail: ?string}
     */
    public function checkModel(string $modelId, ?string $overrideKey = null, ?string $baseUrl = null): array
    {
        $key = $overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey();

        if ($key === '' || PlatformSettings::looksLikePlaceholder($key)) {
            return ['ok' => false, 'reason' => 'not_configured', 'message' => 'No OpenRouter API key is set.', 'detail' => null];
        }

        try {
            $response = $this->request($key)->get(self::MODELS_PATH);
        } catch (ConnectionException) {
            return ['ok' => false, 'reason' => 'timeout', 'message' => 'Could not reach OpenRouter.', 'detail' => null];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'reason' => 'invalid_key', 'message' => 'OpenRouter rejected the key (401 — invalid or revoked).', 'detail' => 'HTTP 401'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'reason' => 'error', 'message' => 'OpenRouter returned an error ('.$response->status().').', 'detail' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 2000)];
        }

        $ids = array_column((array) ($response->json('data') ?? []), 'id');

        if (in_array($modelId, $ids, true)) {
            return ['ok' => true, 'reason' => 'ok', 'message' => 'OpenRouter model "'.$modelId.'" is available.', 'detail' => null];
        }

        return ['ok' => false, 'reason' => 'model_not_found', 'message' => 'OpenRouter model "'.$modelId.'" is not in the live catalog (retired or wrong id).', 'detail' => null];
    }

    /** A short, language-neutral summary of the key's credit, if the provider returned it. */
    private function keySummary(array $data): ?string
    {
        if (isset($data['limit_remaining']) && is_numeric($data['limit_remaining'])) {
            return 'Credit remaining: $'.number_format((float) $data['limit_remaining'], 2);
        }

        if (isset($data['usage']) && is_numeric($data['usage'])) {
            return 'Usage so far: $'.number_format((float) $data['usage'], 2);
        }

        return null;
    }

    /**
     * Result image bytes + mime from an OpenRouter response. Defensive: the image may
     * arrive as message.images[].image_url.url (data URL), a content image part, OR a
     * top-level data[].b64_json. Returns [null, ''] when none is usable.
     *
     * @return array{0: string|null, 1: string}
     */
    public function extractImage(array $response): array
    {
        $message = $response['choices'][0]['message'] ?? [];

        $images = $message['images'] ?? null;
        if (is_array($images)) {
            foreach ($images as $image) {
                $url = $image['image_url']['url'] ?? ($image['url'] ?? null);
                if (is_string($url) && ($decoded = $this->decodeDataUrl($url)) !== null) {
                    return $decoded;
                }
            }
        }

        $content = $message['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $part) {
                $url = $part['image_url']['url'] ?? null;
                if (is_string($url) && ($decoded = $this->decodeDataUrl($url)) !== null) {
                    return $decoded;
                }
            }
        }

        $b64 = $response['data'][0]['b64_json'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $bytes = base64_decode($b64, true);
            if ($bytes !== false) {
                return [$bytes, 'image/png'];
            }
        }

        return [null, ''];
    }

    /**
     * Decode a data: URL to [bytes, mime]. Returns null for a non-data URL or undecodable
     * base64.
     *
     * @return array{0: string, 1: string}|null
     */
    private function decodeDataUrl(string $url): ?array
    {
        if (! str_starts_with($url, self::DATA_URL_PREFIX)) {
            return null;
        }

        if (! preg_match('#^data:(?<mime>[^;]+);base64,(?<data>.+)$#s', $url, $m)) {
            return null;
        }

        $bytes = base64_decode($m['data'], true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return [$bytes, $m['mime']];
    }

    /**
     * Run buildBody($model) against the primary model, retrying transient
     * failures with bounded backoff, then falling back to $fallbackModel. Returns
     * the decoded response of the first success. Re-throws a classified terminal
     * error otherwise.
     *
     * @param  callable(string):array<string,mixed>  $buildBody
     * @return array<string,mixed>
     */
    public function callWithFallback(
        string $operationKey,
        string $primaryModel,
        ?string $fallbackModel,
        callable $buildBody,
    ): array {
        $models = array_values(array_filter([$primaryModel, $fallbackModel]));
        $last = null;

        foreach ($models as $index => $model) {
            $attempt = 0;

            while (true) {
                try {
                    return $this->chat($buildBody($model), $operationKey);
                } catch (OpenRouterException $e) {
                    $last = $e;

                    // Bounded retry on the SAME model only for transient errors.
                    if ($e->isTransient() && $attempt < self::MAX_RETRIES) {
                        $this->sleepBackoff($attempt);
                        $attempt++;

                        continue;
                    }

                    // bad_request is our bug — do not waste a fallback on it.
                    if ($e->errorCode === OpenRouterException::CODE_BAD_REQUEST) {
                        throw $e;
                    }

                    // Move to the next (fallback) model, if any.
                    break;
                }
            }
        }

        throw $last ?? OpenRouterException::make(
            OpenRouterException::CODE_PROVIDER_OUTAGE,
            sprintf('No model produced a response for operation %s.', $operationKey),
        );
    }

    /**
     * Resolve the cost of a generation: inline usage.cost first, then the
     * generation cost endpoint (retried for lag), then an honest unavailable.
     * NEVER guesses a number.
     *
     * @param  array<string,mixed>  $response
     */
    public function parseCost(array $response, ?int $estimatedCostMicroUsd = null): ParsedCost
    {
        $inline = $this->extractInlineCost($response);

        if ($inline !== null) {
            return ParsedCost::inline($inline);
        }

        $generationId = $this->extractGenerationId($response);

        if ($generationId !== null) {
            $endpointCost = $this->lookupGenerationCost($generationId);

            if ($endpointCost !== null) {
                return ParsedCost::fromEndpoint($endpointCost);
            }
        }

        return ParsedCost::unavailable($estimatedCostMicroUsd);
    }

    /** The OpenRouter generation id from a chat response, if present. */
    public function extractGenerationId(array $response): ?string
    {
        $id = $response['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The model OpenRouter actually used (may be the fallback). */
    public function extractModelUsed(array $response, string $requested): string
    {
        $used = $response['model'] ?? null;

        return is_string($used) && $used !== '' ? $used : $requested;
    }

    /**
     * Build the pending request with the three required headers + the server-only
     * bearer. The key is set via withToken so it is never assembled into a logged
     * string here.
     */
    private function request(?string $overrideKey = null): PendingRequest
    {
        $key = $overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey();

        return $this->http
            ->baseUrl((string) config(self::CFG_BASE_URL))
            ->timeout((int) config(self::CFG_TIMEOUT))
            ->withToken(self::headerValue($key))
            ->withHeaders([
                self::HEADER_REFERER => self::headerValue((string) config(self::CFG_REFERER)),
                self::HEADER_TITLE => self::headerValue((string) config(self::CFG_TITLE)),
            ])
            ->acceptJson()
            ->asJson();
    }

    /**
     * A header-safe string: control characters stripped, edges trimmed. Env values are
     * pasted by hand ("Vsio\n", a key with a trailing space) and Guzzle hard-rejects any
     * header containing CR/LF — a paying generation must never fail on that.
     */
    private static function headerValue(string $raw): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw));
    }

    /**
     * The OpenRouter bearer key: the value a super-admin entered in the platform
     * Settings page (DB, encrypted) if present, else the OPENROUTER_API_KEY env var.
     * Resolved per request so a key changed in the UI takes effect without a redeploy.
     */
    private function apiKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::OPENROUTER_API_KEY);
    }

    /**
     * Fail fast with a CLEAR message when the bearer key is missing or still the shipped
     * "REPLACE_WITH_…" placeholder. Otherwise the provider returns an opaque 404 and the
     * real cause — no API key set in the Settings page — is invisible in the event log.
     */
    private function assertKeyConfigured(string $operationKey, string $model): void
    {
        $key = $this->apiKey();

        if ($key !== '' && ! PlatformSettings::looksLikePlaceholder($key)) {
            return;
        }

        $this->logOutcome($operationKey, $model, 'not_configured', null, null, null);

        throw OpenRouterException::make(
            OpenRouterException::CODE_BAD_REQUEST,
            'OpenRouter API key is not configured — set a real key in the platform Settings page.',
            modelUsed: $model,
        );
    }

    /**
     * Turn an HTTP response into a decoded array or a classified exception.
     * Treats every response as hostile: a 200 may wrap a provider error envelope.
     *
     * @return array<string,mixed>
     */
    private function handleResponse(Response $response, string $operationKey, string $model): array
    {
        $status = $response->status();
        $decoded = $this->safeDecode($response->body());

        // Provider error envelope can arrive on a 200 OR an error status.
        $envelope = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if ($response->successful() && $envelope === null) {
            $this->logOutcome($operationKey, $model, 'ok', $status, $this->extractGenerationId($decoded ?? []), $decoded);

            return $decoded ?? [];
        }

        $providerMessage = is_array($envelope) ? (string) ($envelope['message'] ?? '') : $response->body();
        $code = $this->classifyStatus($status, $providerMessage);

        $this->logOutcome($operationKey, $model, $code, $status, null, null);

        throw OpenRouterException::make(
            $code,
            sprintf('OpenRouter %s for model %s (status %d): %s', $code, $model, $status, $this->mask($providerMessage)),
            providerStatus: $status,
            modelUsed: $model,
        );
    }

    /** Map a provider status + message to a stable error code. */
    private function classifyStatus(int $status, string $message): string
    {
        if ($status === self::STATUS_RATE_LIMITED) {
            return OpenRouterException::CODE_RATE_LIMITED;
        }

        if ($status >= self::STATUS_SERVER_MIN) {
            return OpenRouterException::CODE_PROVIDER_OUTAGE;
        }

        $needle = mb_strtolower($message);

        // Content / safety refusals classify as model_refused (release, surface).
        if (str_contains($needle, 'moderation')
            || str_contains($needle, 'content')
            || str_contains($needle, 'safety')
            || str_contains($needle, 'flagged')) {
            return OpenRouterException::CODE_MODEL_REFUSED;
        }

        // 4xx (other than 429) is a malformed request on our side.
        return OpenRouterException::CODE_BAD_REQUEST;
    }

    /** The inline usage cost in USD, if the response carries it. */
    private function extractInlineCost(array $response): ?float
    {
        $cost = $response['usage']['cost'] ?? null;

        if (is_numeric($cost)) {
            return (float) $cost;
        }

        return null;
    }

    /** Query the generation cost endpoint, retrying for the known lag. */
    private function lookupGenerationCost(string $generationId): ?float
    {
        for ($attempt = 0; $attempt < self::COST_LOOKUP_ATTEMPTS; $attempt++) {
            try {
                $response = $this->request()->get(self::GENERATION_PATH, ['id' => $generationId]);
            } catch (ConnectionException) {
                $this->sleepCostBackoff();

                continue;
            }

            if ($response->successful()) {
                $cost = $this->extractEndpointCost($this->safeDecode($response->body()));

                if ($cost !== null) {
                    return $cost;
                }
            }

            $this->sleepCostBackoff();
        }

        return null;
    }

    /** Cost from the generation endpoint payload (total_cost). */
    private function extractEndpointCost(?array $decoded): ?float
    {
        if (! is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? $decoded;
        $cost = $data['total_cost'] ?? ($data['usage'] ?? null);

        if (is_numeric($cost)) {
            return (float) $cost;
        }

        return null;
    }

    /** Decode a JSON body, returning null on garbage (never throws). */
    private function safeDecode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Masked, non-throwing log of a call outcome. NEVER logs the bearer key, the
     * image payload, or the full body — only the model, operation, outcome,
     * status, generation id and parsed cost.
     *
     * @param  array<string,mixed>|null  $decoded
     */
    private function logOutcome(
        string $operationKey,
        string $model,
        string $outcome,
        ?int $status,
        ?string $generationId,
        ?array $decoded,
    ): void {
        try {
            $cost = $decoded !== null ? $this->extractInlineCost($decoded) : null;

            Log::info('openrouter.call', [
                'operation' => $operationKey,
                'model' => $model,
                'outcome' => $outcome,
                'status' => $status,
                'generation_id' => $generationId,
                'cost_usd' => $cost,
                'key' => $this->maskedKeyHint(),
            ]);
        } catch (\Throwable) {
            // A log write must never block or throw into the call path.
        }
    }

    /** A masked hint of the configured key for log correlation (never the key). */
    private function maskedKeyHint(): string
    {
        return $this->mask((string) config(self::CFG_KEY));
    }

    /** Mask any secret-ish string to prefix + ****. */
    private function mask(string $value): string
    {
        if ($value === '') {
            return self::KEY_MASK;
        }

        if (mb_strlen($value) <= self::KEY_VISIBLE_PREFIX) {
            return self::KEY_MASK;
        }

        return mb_substr($value, 0, self::KEY_VISIBLE_PREFIX).self::KEY_MASK;
    }

    /**
     * Exponential jittered backoff for a transient retry. Uses the Sleep facade
     * (not raw usleep) so production behaviour is identical but tests can
     * Sleep::fake() — otherwise real sleeps make the money-path suite flaky
     * (TS-OPENROUTER-003). Under Sleep::fake() no real time passes, so the random
     * jitter stays (production thundering-herd protection) while tests assert the
     * backoff was exercised by COUNT (Sleep::assertSlept*), not exact duration.
     */
    private function sleepBackoff(int $attempt): void
    {
        $base = self::BACKOFF_BASE_MS * (2 ** $attempt);
        $jitter = random_int(0, self::BACKOFF_JITTER_MS);

        Sleep::for($base + $jitter)->milliseconds();
    }

    /** Fixed backoff between generation-cost-endpoint lookups (also via Sleep). */
    private function sleepCostBackoff(): void
    {
        Sleep::for(self::COST_LOOKUP_BACKOFF_MS)->milliseconds();
    }
}
