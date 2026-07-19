<?php

namespace App\Http\Shopify\Controllers;

use App\Http\Middleware\ShopifyFrameAncestors;
use App\Http\Shopify\ShopifyEmbeddedContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * EmbeddedSessionController — the session-token -> partitioned-cookie bridge.
 *
 * The embedded shell proves who it is with an App Bridge JWT (VerifyShopifySessionToken
 * runs first — the JWT is the credential AND the CSRF wall), and this controller turns
 * that proof into a real session login so the FULL Filament merchant panel can render
 * inside the iframe (the session cookie is SameSite=None; Secure; Partitioned).
 *
 * Session fixation: the id is regenerated on login. The shop_domain is stamped into the
 * session so the panel's frame-ancestors CSP can name the exact shop.
 */
final class EmbeddedSessionController
{
    // === CONSTANTS ===
    private const CFG_PANEL_PATH = 'shopify.merchant_panel_path';

    private const KEY_DASHBOARD_URL = 'dashboard_url';

    public function __invoke(Request $request): JsonResponse
    {
        $context = ShopifyEmbeddedContext::of($request);

        Auth::login($context->owner);
        $request->session()->regenerate();
        $request->session()->put(ShopifyFrameAncestors::SESSION_SHOP_DOMAIN, $context->shopDomain());

        $base = rtrim((string) config(self::CFG_PANEL_PATH), '/');

        return WidgetResponse::ok([
            self::KEY_DASHBOARD_URL => $base.'/'.$context->site->slug,
        ]);
    }
}
