<?php

namespace App\Http\Middleware;

use App\Domain\Shopify\Auth\ShopifySessionToken;
use App\Http\Shopify\ShopifyEmbeddedContext;
use App\Http\Shopify\ShopifyShopRouter;
use App\Http\Widget\WidgetResponse;
use App\Models\ShopifyConnection;
use App\Models\User;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyShopifySessionToken — the auth floor for the embedded Shopify-admin surface.
 *
 * Mirrors ResolveWidgetSite's shape exactly: typed JSON rejections only (never 500/HTML),
 * a pre-bind integer routing lookup, then Tenant::run wrapping the WHOLE request so the
 * controllers never see an ambient or stale tenant (TS-TENANCY-001).
 *
 * Flow (fail-closed at each step):
 *  1. read the Bearer session token (App Bridge mints it; Shopify signs it with OUR
 *     client secret) -> 401 missing_token;
 *  2. ShopifySessionToken::verify — ONE 401 invalid_token for EVERY verification failure
 *     (the caller never learns which wall rejected it);
 *  3. shop_domain -> account via ShopifyShopRouter (one integer, pre-bind) -> 401
 *     unknown_shop when the shop was never installed;
 *  4. Tenant::run: re-read the connection through the NORMAL fail-closed scope; an
 *     uninstalled connection answers the SAME unknown_shop (never leak state);
 *  5. resolve the account owner (the provisioner's rule: earliest user of the account);
 *  6. Auth::setUser($owner) — request-scoped auth, NO session write — bind the context,
 *     run the request inside the tenant.
 */
final class VerifyShopifySessionToken
{
    // === CONSTANTS ===
    // Typed rejection codes (the shell renders friendly states off these).
    private const CODE_MISSING_TOKEN = 'missing_token';

    private const CODE_INVALID_TOKEN = 'invalid_token';

    private const CODE_UNKNOWN_SHOP = 'unknown_shop';

    private const CODE_NO_OWNER = 'no_owner';

    // i18n message keys (lang/en/shopify_embedded.php, mirrored in he).
    private const LANG_PREFIX = 'shopify_embedded.auth.';

    public function __construct(
        private readonly ShopifySessionToken $tokens,
        private readonly ShopifyShopRouter $router,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return $this->reject(self::CODE_MISSING_TOKEN, WidgetResponse::STATUS_UNAUTHORIZED);
        }

        $payload = $this->tokens->verify($bearer);

        if ($payload === null) {
            return $this->reject(self::CODE_INVALID_TOKEN, WidgetResponse::STATUS_UNAUTHORIZED);
        }

        $accountId = $this->router->accountIdForShopDomain($payload->shopDomain);

        if ($accountId === null) {
            return $this->reject(self::CODE_UNKNOWN_SHOP, WidgetResponse::STATUS_UNAUTHORIZED);
        }

        // Bind the tenant for the WHOLE request lifecycle; $next runs inside.
        return Tenant::run($accountId, function () use ($request, $next, $payload): Response {
            $connection = ShopifyConnection::query()
                ->where('shop_domain', $payload->shopDomain)
                ->first();

            // No connection at all = a shop we never installed — reject the same as unknown.
            if ($connection === null) {
                return $this->reject(self::CODE_UNKNOWN_SHOP, WidgetResponse::STATUS_UNAUTHORIZED);
            }

            // SELF-HEAL a stale 'uninstalled' status. The Bearer we already verified above is an
            // App Bridge session token — Shopify only mints one for an app that IS installed in
            // this shop, so it is Shopify's own proof of installation. A connection we still mark
            // 'uninstalled' is therefore stale (an uninstall webhook fired, then the app was
            // reopened/reinstalled without our OAuth flipping it back), which is exactly the
            // "We couldn't load your account" dead-end. Reactivate it here; EmbeddedSessionController
            // then re-mints the offline token via token exchange. We NEVER reject a shop Shopify
            // itself says is installed.
            if (! $connection->isInstalled()) {
                $connection->transitionTo(ShopifyConnection::STATUS_INSTALLED, ['reason' => 'embedded_session_reactivation']);
            }

            $site = $connection->site;

            if ($site === null) {
                return $this->reject(self::CODE_UNKNOWN_SHOP, WidgetResponse::STATUS_UNAUTHORIZED);
            }

            // The account owner: the provisioner's canonical rule (earliest user).
            $owner = User::query()
                ->forAccount((int) $connection->account_id)
                ->orderBy('id')
                ->first();

            if ($owner === null) {
                return $this->reject(self::CODE_NO_OWNER, WidgetResponse::STATUS_FORBIDDEN);
            }

            // Request-scoped authentication only — these routes carry no session.
            Auth::setUser($owner);

            (new ShopifyEmbeddedContext($connection, $site, $owner, $payload))->bindTo($request);

            return $next($request);
        });
    }

    /** A typed JSON rejection (localized message + stable code). Never a 500/HTML. */
    private function reject(string $code, int $status): Response
    {
        return WidgetResponse::error($code, __(self::LANG_PREFIX.$code), $status);
    }
}
