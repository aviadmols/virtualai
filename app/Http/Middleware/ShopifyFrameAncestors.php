<?php

namespace App\Http\Middleware;

use App\Domain\Shopify\Auth\ShopifyOAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ShopifyFrameAncestors — the per-shop CSP header Shopify requires on every response
 * rendered inside the admin iframe.
 *
 * Route-targeted, never global. Two fallback modes (middleware parameter):
 *  - strict (default, the embedded entry): no valid shop -> `frame-ancestors 'none'`;
 *  - panel (the Filament merchant panel): no valid shop -> the static Shopify pair, so
 *    the panel keeps working in-iframe on a fresh browser while never being frameable
 *    by an arbitrary site.
 *
 * The shop comes from the `shop` query param (the embedded entry — Shopify signs it)
 * or, for panel navigations, from the session key EmbeddedSessionController stamps at
 * token-login. Every value passes the SAME anchored `{name}.myshopify.com` regex the
 * OAuth boundary trusts, so the interpolated header can never carry an injection.
 */
final class ShopifyFrameAncestors
{
    // === CONSTANTS ===
    public const MODE_STRICT = 'strict';

    public const MODE_PANEL = 'panel';

    // The session key holding the embedded shop (written by EmbeddedSessionController).
    public const SESSION_SHOP_DOMAIN = 'shopify.embedded.shop_domain';

    private const HEADER = 'Content-Security-Policy';

    private const Q_SHOP = 'shop';

    private const VALUE_PER_SHOP = 'frame-ancestors https://%s https://admin.shopify.com;';

    private const VALUE_NONE = "frame-ancestors 'none';";

    private const VALUE_PANEL_DEFAULT = 'frame-ancestors https://admin.shopify.com https://*.myshopify.com;';

    public function handle(Request $request, Closure $next, string $mode = self::MODE_STRICT): Response
    {
        $response = $next($request);

        $response->headers->set(self::HEADER, $this->headerValue($request, $mode));

        return $response;
    }

    private function headerValue(Request $request, string $mode): string
    {
        $shop = ShopifyOAuth::normalizeShopDomain($request->query(self::Q_SHOP));

        if ($shop === null && $request->hasSession()) {
            $shop = ShopifyOAuth::normalizeShopDomain(
                (string) $request->session()->get(self::SESSION_SHOP_DOMAIN, ''),
            );
        }

        if ($shop !== null) {
            return sprintf(self::VALUE_PER_SHOP, $shop);
        }

        return $mode === self::MODE_PANEL ? self::VALUE_PANEL_DEFAULT : self::VALUE_NONE;
    }
}
