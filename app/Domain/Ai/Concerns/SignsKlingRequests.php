<?php

namespace App\Domain\Ai\Concerns;

use App\Domain\Ai\KlingJwt;
use App\Domain\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;

/**
 * SignsKlingRequests — the shared Kling auth + connection probe for the image and video clients.
 *
 * Kling accepts TWO credentials and both are live, so both are supported:
 *
 *   API KEY (current)  — the developer console issues a single static key (`api-key-kling-…`,
 *                        kling.ai/dev/api-key). It is sent verbatim as the bearer.
 *   ACCESS + SECRET    — the legacy pair. No static token exists for it: every request carries a
 *                        fresh HS256 JWT signed with the SECRET key (KlingJwt).
 *
 * The static API key WINS when both are configured (it is what the console hands out today). Each
 * credential resolves like every other provider secret — the Settings-page value (DB, encrypted)
 * first, else the env var.
 *
 * Requires an `$http` HttpFactory property on the user (the clients inject it).
 */
trait SignsKlingRequests
{
    // === CONSTANTS ===
    private const CFG_API_KEY = 'services.kling.api_key';

    private const CFG_ACCESS_KEY = 'services.kling.access_key';

    private const CFG_SECRET_KEY = 'services.kling.secret_key';

    private const CFG_BASE_URL = 'services.kling.base_url';

    private const CFG_TIMEOUT = 'services.kling.timeout';

    private const HTTP_UNAUTHORIZED = 401;

    private const HTTP_FORBIDDEN = 403;

    private const HTTP_SERVER_MIN = 500;

    // A region-host override is honoured ONLY for an https Kling host — a mis-typed or hostile
    // base_url must never receive the platform's Kling credential (the BytePlus guard's twin).
    private const HOST_SUFFIX = 'klingai.com';

    private const REQUIRED_SCHEME = 'https';

    // A no-spend probe: an authenticated GET of a task id that cannot exist. A bad credential
    // answers 401/403; a good one answers a 4xx "task not found" — either way, nothing is generated.
    private const PROBE_PATH = '/v1/images/generations/';

    private const PROBE_TASK_ID = '00000000000000000000';

    private const KEY_VISIBLE_PREFIX = 6;

    private const KEY_MASK = '****';

    /**
     * An authenticated Kling request. $baseUrl overrides the region host — Kling is region-bound
     * (api-singapore is international, api-beijing serves the China account), and a per-model host
     * may be catalogued on ai_models.base_url.
     */
    private function klingRequest(?int $timeout = null, ?string $baseUrl = null, ?string $overrideApiKey = null): PendingRequest
    {
        return $this->http
            ->baseUrl($this->host($baseUrl))
            ->timeout($timeout ?? $this->timeout())
            ->withHeaders(['Authorization' => 'Bearer '.$this->bearer($overrideApiKey)])
            ->acceptJson()
            ->asJson();
    }

    /** The effective region host: a SANITIZED override, else the configured default. */
    private function host(?string $baseUrl = null): string
    {
        return $this->sanitizeBaseUrl($baseUrl) ?? (string) config(self::CFG_BASE_URL);
    }

    /**
     * A region-host override is honoured ONLY when it is an https Kling host (the BytePlus guard's
     * twin). The override reaches us from DB-managed data (ai_models.base_url), and every request
     * carries the PLATFORM's Kling credential — so a mis-typed or hostile host would leak the key
     * off-provider. Anything that is not Kling over https is DROPPED and the default host is used.
     */
    private function sanitizeBaseUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        $host = $parts['host'] ?? '';

        $isKlingHttps = ($parts['scheme'] ?? '') === self::REQUIRED_SCHEME
            && ($host === self::HOST_SUFFIX || str_ends_with($host, '.'.self::HOST_SUFFIX));

        return $isKlingHttps ? trim($url) : null;
    }

    /**
     * The bearer Kling accepts: the STATIC API key verbatim, else a freshly signed JWT for the
     * legacy access/secret pair (minted per request, so it can never be served stale). Empty when
     * no usable credential is configured.
     */
    private function bearer(?string $overrideApiKey = null): string
    {
        $apiKey = $this->usable($overrideApiKey ?? $this->apiKey());

        if ($apiKey !== '') {
            return $apiKey;
        }

        return KlingJwt::token($this->usable($this->accessKey()), $this->usable($this->secretKey()));
    }

    /**
     * Test connectivity WITHOUT spending. $overrideKey is the API key typed into the Settings form;
     * the legacy pair always comes from storage (a write-only secret is never round-tripped to the
     * browser), so a pair must be SAVED before it can be tested. $baseUrl probes a region host.
     *
     * @return array{ok: bool, reason: string, message: string, detail: ?string}
     */
    public function checkConnection(?string $overrideKey = null, ?string $baseUrl = null): array
    {
        $apiKey = $this->usable($overrideKey ?? $this->apiKey());
        $hasPair = $this->usable($this->accessKey()) !== '' && $this->usable($this->secretKey()) !== '';

        if ($apiKey === '' && ! $hasPair) {
            return [
                'ok' => false,
                'reason' => 'not_configured',
                'message' => 'No Kling credential is set — save the API key (kling.ai/dev/api-key), or the legacy access key AND secret key.',
                'detail' => null,
            ];
        }

        try {
            $response = $this->klingRequest(baseUrl: $baseUrl, overrideApiKey: $apiKey !== '' ? $apiKey : null)
                ->get(self::PROBE_PATH.self::PROBE_TASK_ID);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'reason' => 'timeout', 'message' => 'Could not reach Kling (check the network).', 'detail' => $e->getMessage()];
        }

        if (in_array($response->status(), [self::HTTP_UNAUTHORIZED, self::HTTP_FORBIDDEN], true)) {
            return ['ok' => false, 'reason' => 'invalid_key', 'message' => 'Kling rejected the credentials ('.$response->status().').', 'detail' => 'HTTP '.$response->status()];
        }

        // Any authenticated answer (incl. a 4xx "task not found" for the impossible probe id)
        // proves the credential was accepted.
        if ($response->status() < self::HTTP_SERVER_MIN) {
            return ['ok' => true, 'reason' => 'ok', 'message' => 'Kling accepted the credentials.', 'detail' => null];
        }

        return ['ok' => false, 'reason' => 'error', 'message' => 'Kling returned an error ('.$response->status().').', 'detail' => 'HTTP '.$response->status()];
    }

    private function apiKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::KLING_API_KEY);
    }

    private function accessKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::KLING_ACCESS_KEY);
    }

    private function secretKey(): string
    {
        return (string) app(PlatformSettings::class)->resolve(PlatformSettings::KLING_SECRET_KEY);
    }

    private function timeout(): int
    {
        return (int) config(self::CFG_TIMEOUT);
    }

    /** A credential that is actually usable: trimmed, and never a shipped placeholder. */
    private function usable(?string $key): string
    {
        $key = trim((string) $key);

        return PlatformSettings::looksLikePlaceholder($key) ? '' : $key;
    }

    /**
     * Mask the credential in play for logs — the API key if that is the active one, else the access
     * key. The SECRET key is NEVER logged, masked or otherwise.
     */
    private function maskedCredential(): string
    {
        $key = $this->usable($this->apiKey()) ?: $this->usable($this->accessKey());

        return $key === '' ? '' : substr($key, 0, self::KEY_VISIBLE_PREFIX).self::KEY_MASK;
    }
}
