<?php

namespace App\Http\Shopify\Controllers;

use App\Domain\Shopify\Auth\ShopifyOAuth;
use App\Domain\Shopify\Auth\ShopifyOAuthException;
use App\Domain\Shopify\ShopifyCredentials;
use App\Http\Shopify\ShopifyShopRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * EmbeddedAppController — the app's `application_url`: what the Shopify admin loads
 * inside the iframe when a merchant opens Vsio.
 *
 * Stateless (no session, no cookies — they don't exist in a third-party iframe). The
 * response is one of three branches, each fail-closed:
 *
 *  - UNKNOWN shop, framed (`embedded=1`): the BREAKOUT page — App Bridge escapes the
 *    iframe top-level to /shopify/install, where the session-bound OAuth state works;
 *  - UNKNOWN shop, not framed: a plain 302 to the same install URL;
 *  - KNOWN shop: the embedded SHELL (App Bridge + the session-token bootstrap flow).
 *
 * Shopify signs every admin iframe load; when an `hmac` is present it MUST verify
 * (the same wall as OAuthController::install). A missing/invalid shop is a typed 403.
 */
final class EmbeddedAppController
{
    // === CONSTANTS ===
    private const Q_SHOP = 'shop';

    private const Q_HMAC = 'hmac';

    private const Q_EMBEDDED = 'embedded';

    private const Q_LOCALE = 'locale';

    private const EMBEDDED_FLAG = '1';

    private const VIEW_SHELL = 'shopify.embedded.app';

    private const VIEW_BREAKOUT = 'shopify.embedded.breakout';

    private const ROUTE_INSTALL = 'shopify.install';

    private const ROUTE_SESSION = 'shopify.app.session';

    private const ROUTE_BOOTSTRAP = 'shopify.app.bootstrap';

    // Locale prefixes the shell renders RTL Hebrew for; everything else is English.
    private const LOCALE_HE_PREFIX = 'he';

    private const LOCALE_HE = 'he';

    private const LOCALE_EN = 'en';

    private const LOG_DENIED = 'shopify.embedded.denied';

    private const ERROR_CONTENT_TYPE = 'text/plain';

    private const ERROR_BODY_TEMPLATE = "Shopify app load failed: %s\n(code: %s)";

    public function __construct(
        private readonly ShopifyOAuth $oauth,
        private readonly ShopifyCredentials $credentials,
        private readonly ShopifyShopRouter $router,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        try {
            $query = $request->query();

            // Shopify signs the iframe load; a present-but-forged hmac is hostile.
            if (isset($query[self::Q_HMAC]) && ! $this->oauth->verifyRequestHmac($query)) {
                throw ShopifyOAuthException::invalidHmac($request->query(self::Q_SHOP));
            }

            $shop = ShopifyOAuth::normalizeShopDomain($request->query(self::Q_SHOP));

            if ($shop === null) {
                throw ShopifyOAuthException::invalidShop($request->query(self::Q_SHOP));
            }

            if (! $this->credentials->isConfigured()) {
                throw ShopifyOAuthException::notConfigured();
            }

            app()->setLocale($this->locale($request));

            // Unknown shop -> the install flow (top-level: OAuth state is session-bound).
            if ($this->router->accountIdForShopDomain($shop) === null) {
                $installUrl = route(self::ROUTE_INSTALL, [self::Q_SHOP => $shop]);

                if ($request->query(self::Q_EMBEDDED) === self::EMBEDDED_FLAG) {
                    return response()->view(self::VIEW_BREAKOUT, [
                        'apiKey' => $this->credentials->clientId(),
                        'installUrl' => $installUrl,
                    ]);
                }

                return redirect()->to($installUrl);
            }

            // Known shop: the shell. All data flows through the token-authed API.
            return response()->view(self::VIEW_SHELL, [
                'apiKey' => $this->credentials->clientId(),
                'shop' => $shop,
                'sessionUrl' => route(self::ROUTE_SESSION),
                'bootstrapUrl' => route(self::ROUTE_BOOTSTRAP),
            ]);
        } catch (ShopifyOAuthException $e) {
            return $this->denied($e);
        }
    }

    /** `he*` renders Hebrew/RTL; everything else English. */
    private function locale(Request $request): string
    {
        $locale = strtolower((string) $request->query(self::Q_LOCALE, ''));

        return str_starts_with($locale, self::LOCALE_HE_PREFIX) ? self::LOCALE_HE : self::LOCALE_EN;
    }

    /** The typed denial (403/502, never 500) — same shape as OAuthController. */
    private function denied(ShopifyOAuthException $e): Response
    {
        Log::warning(self::LOG_DENIED, ['code' => $e->errorCode, 'shop_domain' => $e->shopDomain]);

        return response(
            sprintf(self::ERROR_BODY_TEMPLATE, $e->getMessage(), $e->errorCode),
            $e->httpStatus(),
            ['Content-Type' => self::ERROR_CONTENT_TYPE],
        );
    }
}
