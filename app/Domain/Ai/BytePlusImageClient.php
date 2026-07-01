<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Credits\CreditMath;
use App\Domain\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * BytePlusImageClient — the BytePlus / Seedream adapter for try-on image generation.
 *
 * POSTs /images/generations with a text prompt + input image URLs (shopper + product) and
 * returns the composite image bytes. Speaks the ImageGenerationProvider contract so
 * TryOnGenerationCaller stays provider-agnostic. Masks the bearer in logs; classifies
 * failures into the shared (provider-neutral) OpenRouterException codes so the money path's
 * catch blocks work unchanged.
 *
 * MONEY-SAFETY: BytePlus returns NO per-request USD cost (only internal credits), so
 * parseCost uses the admin-entered per-image price (the AiModel cost hint) as the
 * authoritative flat rate — and returns UNAVAILABLE (never charges) when no price is set.
 */
final class BytePlusImageClient implements ImageGenerationProvider
{
    // === CONSTANTS ===
    private const IMAGES_PATH = '/images/generations';

    private const CFG_KEY = 'services.byteplus.api_key';
    private const CFG_BASE_URL = 'services.byteplus.base_url';
    private const CFG_TIMEOUT = 'services.byteplus.timeout';
    private const CFG_PROBE_MODEL = 'services.byteplus.probe_model';

    private const MAX_RETRIES = 1;
    private const BACKOFF_BASE_MS = 400;
    private const BACKOFF_JITTER_MS = 250;

    private const STATUS_BAD_PARAMS = 400; // key accepted, params rejected — bearer is valid
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
            sprintf('No BytePlus model produced a response for operation %s.', $operationKey),
        );
    }

    /** POST the images/generations body; classify the response into decoded array or throw. */
    private function generate(array $body, string $operationKey): array
    {
        $model = (string) ($body['model'] ?? 'unknown');

        try {
            $response = $this->request()->post(self::IMAGES_PATH, $body);
        } catch (ConnectionException $e) {
            $this->logOutcome($operationKey, $model, 'timeout', null);

            throw OpenRouterException::make(
                OpenRouterException::CODE_MODEL_TIMEOUT,
                sprintf('BytePlus call timed out for model %s.', $model),
                modelUsed: $model,
                previous: $e,
            );
        }

        return $this->handleResponse($response, $operationKey, $model);
    }

    /** @return array<string,mixed> */
    private function handleResponse(Response $response, string $operationKey, string $model): array
    {
        $status = $response->status();
        $decoded = is_array($response->json()) ? $response->json() : [];
        $envelope = $decoded['error'] ?? null;

        if ($response->successful() && $envelope === null) {
            $this->logOutcome($operationKey, $model, 'ok', $status);

            return $decoded;
        }

        $message = is_array($envelope) ? (string) ($envelope['message'] ?? '') : (string) $response->body();
        $code = $this->classifyStatus($status);
        $this->logOutcome($operationKey, $model, $code, $status);

        throw OpenRouterException::make(
            $code,
            sprintf('BytePlus error (%d) for model %s: %s', $status, $model, $message),
            modelUsed: $model,
            providerStatus: $status,
        );
    }

    public function extractModelUsed(array $response, string $requested): string
    {
        $used = $response['model'] ?? null;

        return is_string($used) && $used !== '' ? $used : $requested;
    }

    public function extractGenerationId(array $response): ?string
    {
        return null; // BytePlus returns no correlatable generation id we consume.
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
     * Result image bytes + mime: base64 (response_format=b64_json) preferred, else a
     * signed URL downloaded server-side. Returns [null, ''] when none is usable.
     *
     * @return array{0: string|null, 1: string}
     */
    public function extractImage(array $response): array
    {
        $item = $response['data'][0] ?? [];

        $b64 = $item['b64_json'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $bytes = base64_decode($b64, true);
            if ($bytes !== false) {
                return [$bytes, 'image/png'];
            }
        }

        $url = $item['url'] ?? null;
        if (is_string($url) && str_starts_with($url, 'http')) {
            try {
                $img = $this->http->timeout((int) config(self::CFG_TIMEOUT))->get($url);
                if ($img->successful()) {
                    return [$img->body(), $img->header('Content-Type') ?: 'image/png'];
                }
            } catch (ConnectionException) {
                // fall through to [null, '']
            }
        }

        return [null, ''];
    }

    public function checkConnection(?string $overrideKey = null): array
    {
        $key = $overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey();

        if ($key === '' || PlatformSettings::looksLikePlaceholder($key)) {
            return ['ok' => false, 'reason' => 'not_configured', 'message' => 'No BytePlus API key is set.', 'detail' => null];
        }

        try {
            $response = $this->request($key)->post(self::IMAGES_PATH, [
                'model' => (string) config(self::CFG_PROBE_MODEL),
                'prompt' => 'ping',
                'size' => '1K',
            ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'reason' => 'timeout', 'message' => 'Could not reach BytePlus.', 'detail' => null];
        }

        $status = $response->status();

        if ($status === self::STATUS_UNAUTHORIZED) {
            return ['ok' => false, 'reason' => 'invalid_key', 'message' => 'BytePlus rejected the key (401 — invalid or revoked).', 'detail' => null];
        }

        // 200 (produced) OR 400 (key accepted, params rejected) both prove the bearer works.
        if ($response->successful() || $status === self::STATUS_BAD_PARAMS) {
            return ['ok' => true, 'reason' => 'ok', 'message' => 'Connected to BytePlus.', 'detail' => null];
        }

        if ($status === self::STATUS_FORBIDDEN) {
            return ['ok' => false, 'reason' => 'error', 'message' => 'Key valid but the model/region is not permitted (403).', 'detail' => null];
        }

        return ['ok' => false, 'reason' => 'error', 'message' => 'BytePlus returned an error ('.$status.').', 'detail' => null];
    }

    private function request(?string $overrideKey = null): PendingRequest
    {
        return $this->http
            ->baseUrl((string) config(self::CFG_BASE_URL))
            ->timeout((int) config(self::CFG_TIMEOUT))
            ->withToken($overrideKey !== null && $overrideKey !== '' ? $overrideKey : $this->apiKey())
            ->acceptJson()
            ->asJson();
    }

    /** The BytePlus bearer: the Settings-page value (DB, encrypted) if set, else the env var. */
    private function apiKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::BYTEPLUS_API_KEY);
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
        Log::info('byteplus.call', [
            'operation' => $operationKey,
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'key' => $this->maskKey(),
        ]);
    }

    private function maskKey(): string
    {
        $key = (string) config(self::CFG_KEY);

        return $key === '' ? '' : substr($key, 0, self::KEY_VISIBLE_PREFIX).self::KEY_MASK;
    }
}
