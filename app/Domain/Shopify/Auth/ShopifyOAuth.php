<?php

namespace App\Domain\Shopify\Auth;

use App\Domain\Shopify\ShopifyCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ShopifyOAuth — the authorize-URL builder, the request-HMAC verifier, and the
 * code -> OFFLINE access-token exchange. The only class that speaks OAuth to Shopify.
 *
 * Fail-closed by construction:
 *  - a shop that is not `{name}.myshopify.com` NEVER reaches an HTTP call (the regex is
 *    the wall: it stops an attacker pointing the exchange at their own host);
 *  - a missing/forged request hmac rejects (hash_equals over the sorted query minus hmac,
 *    the same scheme the PayPlus rail uses on its raw body);
 *  - unset app credentials are the TYPED not_configured failure, never a 500.
 *
 * Token type is OFFLINE (grant_options omitted): background jobs must keep working after
 * the merchant closes the admin (docs/shopify/DECISIONS.md §2). The access token is
 * returned as a value object and handed straight to ShopifyInstaller — never logged.
 */
final class ShopifyOAuth
{
    // === CONSTANTS ===
    // The ONLY shop-domain shape we ever talk to. Anchored, lowercase, no userinfo/port/path.
    // The `D` (PCRE_DOLLAR_ENDONLY) flag makes `$` match the absolute end, NOT before a
    // trailing newline — so a "foo.myshopify.com\n" can never pass (this regex also gates
    // the session-token `dest`, which is not trim()'d before the check).
    public const SHOP_DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D';

    private const SCHEME = 'https://';

    private const PATH_AUTHORIZE = '/admin/oauth/authorize';

    private const PATH_ACCESS_TOKEN = '/admin/oauth/access_token';

    // Query params on the authorize redirect.
    private const Q_CLIENT_ID = 'client_id';

    private const Q_SCOPE = 'scope';

    private const Q_REDIRECT_URI = 'redirect_uri';

    private const Q_STATE = 'state';

    // Params excluded from the request-HMAC message (Shopify signs everything else).
    private const HMAC_PARAM = 'hmac';

    private const SIGNATURE_PARAM = 'signature';

    private const HMAC_ALGO = 'sha256';

    // Token-exchange body + response keys.
    private const B_CLIENT_ID = 'client_id';

    private const B_CLIENT_SECRET = 'client_secret';

    private const B_CODE = 'code';

    private const R_ACCESS_TOKEN = 'access_token';

    private const R_SCOPE = 'scope';

    private const R_EXPIRES_IN = 'expires_in';

    // Token-exchange grant: an App Bridge session token -> an EXPIRING offline access token.
    // Shopify no longer accepts non-expiring offline tokens for the Admin API, so the legacy
    // authorization-code offline token is refreshed to an expiring one on every embedded load.
    private const B_GRANT_TYPE = 'grant_type';

    private const B_SUBJECT_TOKEN = 'subject_token';

    private const B_SUBJECT_TOKEN_TYPE = 'subject_token_type';

    private const B_REQUESTED_TOKEN_TYPE = 'requested_token_type';

    private const GRANT_TOKEN_EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    private const SUBJECT_TOKEN_TYPE_ID = 'urn:ietf:params:oauth:token-type:id_token';

    private const REQUESTED_TOKEN_TYPE_OFFLINE = 'urn:shopify:params:oauth:token-type:offline-access-token';

    // THE fix for "Non-expiring access tokens are no longer accepted": Shopify defaults to a
    // NON-expiring offline token unless the request opts in with expiring=1. Without this the
    // exchange succeeds but every token it returns is rejected by the Admin API.
    private const B_EXPIRING = 'expiring';

    private const EXPIRING_TRUE = '1';

    private const CFG_TIMEOUT = 'services.shopify.timeout';

    private const CFG_SCOPES = 'shopify.scopes';

    private const CFG_API_VERSION = 'shopify.api_version';

    private const DEFAULT_TIMEOUT = 30;

    private const LOG_EXCHANGE_FAILED = 'shopify.oauth.exchange_failed';

    private const LOG_TRANSPORT_ERROR = 'shopify.oauth.transport_error';

    public function __construct(
        private readonly ShopifyCredentials $credentials,
    ) {}

    /** True only for a canonical `{name}.myshopify.com` host. Everything else is hostile. */
    public static function isValidShopDomain(?string $shop): bool
    {
        return $shop !== null && preg_match(self::SHOP_DOMAIN_PATTERN, $shop) === 1;
    }

    /** Normalize a merchant-typed shop ("My-Shop", "https://my-shop.myshopify.com/") for validation. */
    public static function normalizeShopDomain(?string $shop): ?string
    {
        $shop = strtolower(trim((string) $shop));

        if ($shop === '') {
            return null;
        }

        $shop = preg_replace('#^https?://#', '', $shop) ?? '';
        $shop = explode('/', $shop)[0];

        // A bare handle ("my-shop") means the canonical myshopify host.
        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return self::isValidShopDomain($shop) ? $shop : null;
    }

    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured();
    }

    /**
     * The Shopify grant screen the merchant is redirected to. Offline token (no
     * grant_options). Throws the typed not_configured/invalid_shop failures instead of
     * building a URL that could not possibly work.
     */
    public function authorizeUrl(string $shop, string $state, string $redirectUri): string
    {
        if (! self::isValidShopDomain($shop)) {
            throw ShopifyOAuthException::invalidShop($shop);
        }

        if (! $this->isConfigured()) {
            throw ShopifyOAuthException::notConfigured();
        }

        $query = http_build_query([
            self::Q_CLIENT_ID => $this->credentials->clientId(),
            self::Q_SCOPE => (string) config(self::CFG_SCOPES),
            self::Q_REDIRECT_URI => $redirectUri,
            self::Q_STATE => $state,
        ]);

        return self::SCHEME.$shop.self::PATH_AUTHORIZE.'?'.$query;
    }

    /**
     * Verify Shopify's request HMAC over the query string (install + callback + any
     * admin-link request). Sorted params minus hmac/signature, hex HMAC-SHA256 with the
     * client secret, hash_equals. FAILS CLOSED on a missing secret or a missing hmac.
     *
     * @param  array<string,mixed>  $query
     */
    public function verifyRequestHmac(array $query): bool
    {
        $secret = $this->credentials->clientSecret();
        $sent = (string) ($query[self::HMAC_PARAM] ?? '');

        if ($secret === '' || $sent === '') {
            return false;
        }

        unset($query[self::HMAC_PARAM], $query[self::SIGNATURE_PARAM]);
        ksort($query);

        $expected = hash_hmac(self::HMAC_ALGO, http_build_query($query), $secret);

        return hash_equals($expected, $sent);
    }

    /**
     * Exchange the one-time authorization code for the store's OFFLINE access token.
     * Any non-2xx / malformed body / transport error is the typed token_exchange_failed
     * (a 502 to the merchant) — never a 500, and never a logged token.
     */
    public function exchangeCode(string $shop, string $code): ShopifyAccessToken
    {
        if (! self::isValidShopDomain($shop)) {
            throw ShopifyOAuthException::invalidShop($shop);
        }

        if (! $this->isConfigured()) {
            throw ShopifyOAuthException::notConfigured();
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((int) (config(self::CFG_TIMEOUT) ?? self::DEFAULT_TIMEOUT))
                ->post(self::SCHEME.$shop.self::PATH_ACCESS_TOKEN, [
                    self::B_CLIENT_ID => $this->credentials->clientId(),
                    self::B_CLIENT_SECRET => $this->credentials->clientSecret(),
                    self::B_CODE => $code,
                ]);
        } catch (Throwable $e) {
            Log::warning(self::LOG_TRANSPORT_ERROR, ['shop_domain' => $shop, 'exception' => $e::class]);

            throw ShopifyOAuthException::tokenExchangeFailed($shop, 0);
        }

        if (! $response->successful()) {
            Log::warning(self::LOG_EXCHANGE_FAILED, ['shop_domain' => $shop, 'status' => $response->status()]);

            throw ShopifyOAuthException::tokenExchangeFailed($shop, $response->status());
        }

        $token = (string) ($response->json(self::R_ACCESS_TOKEN) ?? '');

        if ($token === '') {
            Log::warning(self::LOG_EXCHANGE_FAILED, ['shop_domain' => $shop, 'status' => $response->status(), 'reason' => 'no_access_token']);

            throw ShopifyOAuthException::tokenExchangeFailed($shop, $response->status());
        }

        return new ShopifyAccessToken(
            accessToken: $token,
            scopes: (string) ($response->json(self::R_SCOPE) ?? config(self::CFG_SCOPES)),
            apiVersion: (string) config(self::CFG_API_VERSION),
        );
    }

    /**
     * Token exchange: turn a valid App Bridge SESSION token into an EXPIRING offline access
     * token. Shopify no longer accepts non-expiring offline tokens (the legacy
     * authorization-code offline token) for the Admin API, so the embedded app refreshes the
     * store's token this way on load. Same fail-closed shape as exchangeCode; the 4xx body is
     * logged (no secret) so an unexpected token-exchange rejection is diagnosable.
     */
    public function exchangeSessionToken(string $shop, string $sessionToken): ShopifyAccessToken
    {
        if (! self::isValidShopDomain($shop)) {
            throw ShopifyOAuthException::invalidShop($shop);
        }

        if (! $this->isConfigured()) {
            throw ShopifyOAuthException::notConfigured();
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((int) (config(self::CFG_TIMEOUT) ?? self::DEFAULT_TIMEOUT))
                ->post(self::SCHEME.$shop.self::PATH_ACCESS_TOKEN, [
                    self::B_CLIENT_ID => $this->credentials->clientId(),
                    self::B_CLIENT_SECRET => $this->credentials->clientSecret(),
                    self::B_GRANT_TYPE => self::GRANT_TOKEN_EXCHANGE,
                    self::B_SUBJECT_TOKEN => $sessionToken,
                    self::B_SUBJECT_TOKEN_TYPE => self::SUBJECT_TOKEN_TYPE_ID,
                    self::B_REQUESTED_TOKEN_TYPE => self::REQUESTED_TOKEN_TYPE_OFFLINE,
                    self::B_EXPIRING => self::EXPIRING_TRUE,
                ]);
        } catch (Throwable $e) {
            Log::warning(self::LOG_TRANSPORT_ERROR, ['shop_domain' => $shop, 'exception' => $e::class]);

            throw ShopifyOAuthException::tokenExchangeFailed($shop, 0);
        }

        if (! $response->successful()) {
            Log::warning(self::LOG_EXCHANGE_FAILED, [
                'shop_domain' => $shop,
                'status' => $response->status(),
                'grant' => 'token_exchange',
                'body' => Str::limit((string) $response->body(), 300),
            ]);

            throw ShopifyOAuthException::tokenExchangeFailed($shop, $response->status());
        }

        $token = (string) ($response->json(self::R_ACCESS_TOKEN) ?? '');

        if ($token === '') {
            Log::warning(self::LOG_EXCHANGE_FAILED, ['shop_domain' => $shop, 'grant' => 'token_exchange', 'reason' => 'no_access_token']);

            throw ShopifyOAuthException::tokenExchangeFailed($shop, $response->status());
        }

        $expiresIn = $response->json(self::R_EXPIRES_IN);

        return new ShopifyAccessToken(
            accessToken: $token,
            scopes: (string) ($response->json(self::R_SCOPE) ?? config(self::CFG_SCOPES)),
            apiVersion: (string) config(self::CFG_API_VERSION),
            expiresIn: is_numeric($expiresIn) ? (int) $expiresIn : null,
        );
    }
}
