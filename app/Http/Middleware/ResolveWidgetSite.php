<?php

namespace App\Http\Middleware;

use App\Http\Widget\SiteRouter;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use App\Models\Site;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveWidgetSite — the widget-auth middleware. The auth floor for every /widget/v1
 * route. Runs entirely as TYPED JSON rejections (401/403), never a 500/HTML to the widget.
 *
 * Flow (fail-closed at each step):
 *  1. read the PUBLIC site_key (header X-Tray-Site-Key, or ?site_key= for the GET bootstrap);
 *  2. resolve the OWNING account via SiteRouter (one integer routing lookup, pre-bind);
 *     an unknown key -> 401 (never reveal whether a key exists beyond unknown/known);
 *  3. Tenant::run($accountId, …) binds the tenant for the WHOLE request lifecycle and
 *     clears it in finally (never ambient — TS-TENANCY-001) — $next runs INSIDE the bind;
 *  4. re-read the full Site through the NORMAL fail-closed global scope (account-scoped);
 *  5. verify the request Origin against the site's allowed_origins allow-list -> 403 on miss;
 *  6. OPTIONAL HMAC on sensitive POSTs (X-Tray-Signature over the body, keyed by the
 *     server-side widget_secret) when enabled -> 403 on missing/invalid/expired;
 *  7. stash the WidgetContext + apply CORS for the allow-listed origin ONLY.
 *
 * The widget_secret + OpenRouter key are NEVER read into a response; the secret is used
 * only to recompute the HMAC server-side and is decrypted on demand by the Site cast.
 */
final class ResolveWidgetSite
{
    // === CONSTANTS ===
    private const HEADER_SITE_KEY_CONFIG = 'widget.headers.site_key';
    private const HEADER_SIGNATURE_CONFIG = 'widget.headers.signature';
    private const HEADER_TIMESTAMP_CONFIG = 'widget.headers.timestamp';
    private const QUERY_SITE_KEY_CONFIG = 'widget.query_site_key';
    private const HMAC_ENABLED_CONFIG = 'widget.hmac.enabled';
    private const HMAC_MAX_SKEW_CONFIG = 'widget.hmac.max_skew';

    private const HMAC_ALGO = 'sha256';

    // CORS response headers for the allow-listed origin only.
    private const CORS_ALLOW_METHODS = 'GET, POST, OPTIONS';
    private const CORS_ALLOW_HEADERS = 'Content-Type, X-Tray-Site-Key, X-Tray-Signature, X-Tray-Timestamp';
    private const CORS_MAX_AGE = '600';

    // Shopify serves theme-editor/share previews from ROTATING *.shopifypreview.com subdomains
    // that can never be pre-registered — a SHOPIFY site accepts them so the widget works in the
    // merchant's preview. (Origin is an anti-hotlinking wall, not an auth boundary — the real
    // gates are the site_key + rate limits + the lead/credit gates.)
    private const SHOPIFY_PREVIEW_SUFFIX = '.shopifypreview.com';

    private const HTTPS_SCHEME = 'https://';

    public function __construct(
        private readonly SiteRouter $router,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $siteKey = $this->readSiteKey($request);

        if ($siteKey === null || $siteKey === '') {
            return $this->reject('unknown_site', WidgetResponse::STATUS_UNAUTHORIZED, 'auth.unknown_site');
        }

        $accountId = $this->router->accountIdForSiteKey($siteKey);

        if ($accountId === null) {
            return $this->reject('unknown_site', WidgetResponse::STATUS_UNAUTHORIZED, 'auth.unknown_site');
        }

        // Bind the tenant for the WHOLE request lifecycle; $next runs inside, the bind
        // clears in finally. The widget controllers never see an ambient/stale tenant.
        return Tenant::run($accountId, function () use ($request, $next, $siteKey): Response {
            $site = Site::query()->where('site_key', $siteKey)->first();

            // Re-read through the global scope: if the routing integer and the scoped read
            // ever disagree (impossible under globally-unique ids), fail closed.
            if ($site === null) {
                return $this->reject('unknown_site', WidgetResponse::STATUS_UNAUTHORIZED, 'auth.unknown_site');
            }

            $origin = $this->readOrigin($request);

            if (! $this->originAllowed($site, $origin)) {
                return $this->reject('origin_not_allowed', WidgetResponse::STATUS_FORBIDDEN, 'auth.origin_not_allowed');
            }

            $hmac = $this->verifyHmac($request, $site);

            if ($hmac !== null) {
                return $hmac; // a typed 403 (signature required / invalid / expired)
            }

            (new WidgetContext($site, $origin))->bindTo($request);

            $response = $next($request);

            return $this->applyCors($response, $origin);
        });
    }

    /** Read the public site_key from the header, falling back to the query param (GET). */
    private function readSiteKey(Request $request): ?string
    {
        $header = (string) $request->header((string) config(self::HEADER_SITE_KEY_CONFIG), '');

        if ($header !== '') {
            return $header;
        }

        $query = $request->query((string) config(self::QUERY_SITE_KEY_CONFIG));

        return is_string($query) ? $query : null;
    }

    /** The request Origin header (browser-set; the host the widget runs on). */
    private function readOrigin(Request $request): string
    {
        return (string) $request->header('Origin', '');
    }

    /**
     * True iff the Origin exactly matches one of the site's allow-listed origins. An
     * empty/absent Origin never passes (a browser fetch from an allowed page always sends
     * one); matching is exact (scheme + host + port), never a substring.
     */
    private function originAllowed(Site $site, string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        // The site's OWN domain origin is always allowed — the widget must run on the
        // store's own domain without a manual allowed-origins step. allowed_origins adds
        // any EXTRA origins (e.g. a staging host).
        $domainOrigin = Site::originFromDomain($site->domain);

        if ($domainOrigin !== null && $origin === $domainOrigin) {
            return true;
        }

        if (in_array($origin, $site->allowed_origins ?? [], true)) {
            return true;
        }

        // A Shopify site also accepts the rotating theme-preview origins.
        return $site->isShopify() && $this->isShopifyPreviewOrigin($origin);
    }

    /** True for https://{anything}.shopifypreview.com — exact suffix on the HOST, https only. */
    private function isShopifyPreviewOrigin(string $origin): bool
    {
        if (! str_starts_with($origin, self::HTTPS_SCHEME)) {
            return false;
        }

        $host = (string) parse_url($origin, PHP_URL_HOST);

        return $host !== '' && str_ends_with($host, self::SHOPIFY_PREVIEW_SUFFIX);
    }

    /**
     * Optional HMAC verification. Disabled by default (the auth floor is site_key +
     * Origin). When enabled, a sensitive POST must carry a valid X-Tray-Signature =
     * HMAC-SHA256(timestamp."\n".rawBody, widget_secret) within the skew window. Returns a
     * typed 403 response on a problem, or null when the request passes / HMAC is off.
     */
    private function verifyHmac(Request $request, Site $site): ?Response
    {
        if (! (bool) config(self::HMAC_ENABLED_CONFIG)) {
            return null;
        }

        // Only mutating requests carry a signed body.
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $signature = (string) $request->header((string) config(self::HEADER_SIGNATURE_CONFIG), '');
        $timestamp = (string) $request->header((string) config(self::HEADER_TIMESTAMP_CONFIG), '');

        if ($signature === '' || $timestamp === '') {
            return $this->reject('signature_required', WidgetResponse::STATUS_FORBIDDEN, 'auth.signature_required');
        }

        $skew = abs(now()->getTimestamp() - (int) $timestamp);

        if ($skew > (int) config(self::HMAC_MAX_SKEW_CONFIG)) {
            return $this->reject('signature_expired', WidgetResponse::STATUS_FORBIDDEN, 'auth.signature_expired');
        }

        $secret = (string) $site->widget_secret; // decrypted on demand; never serialized
        $expected = hash_hmac(self::HMAC_ALGO, $timestamp."\n".$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->reject('signature_invalid', WidgetResponse::STATUS_FORBIDDEN, 'auth.signature_invalid');
        }

        return null;
    }

    /** Add CORS headers for the single allow-listed origin (never a wildcard). */
    private function applyCors(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', self::CORS_ALLOW_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::CORS_ALLOW_HEADERS);
        $response->headers->set('Access-Control-Max-Age', self::CORS_MAX_AGE);

        return $response;
    }

    /** A typed JSON rejection (localized message + stable code). Never a 500/HTML. */
    private function reject(string $code, int $status, string $messageKey): Response
    {
        return WidgetResponse::error($code, __('widget_api.'.$messageKey), $status);
    }
}
