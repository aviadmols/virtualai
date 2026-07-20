<?php

namespace App\Http\Shopify\Controllers;

use App\Domain\Shopify\Auth\ShopifyOAuth;
use App\Http\Middleware\ShopifyFrameAncestors;
use App\Http\Shopify\ShopifyEmbeddedContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * EmbeddedSessionController — the session-token -> partitioned-cookie bridge.
 *
 * The embedded shell proves who it is with an App Bridge JWT (VerifyShopifySessionToken
 * runs first — the JWT is the credential AND the CSRF wall), and this controller turns
 * that proof into a real session login so the FULL Filament merchant panel can render
 * inside the iframe (the session cookie is SameSite=None; Secure; Partitioned).
 *
 * It ALSO refreshes the store's OFFLINE Admin-API token here (TOKEN EXCHANGE): Shopify no
 * longer accepts the legacy non-expiring offline token, so the App Bridge session token is
 * exchanged for an EXPIRING offline token and re-stored on the connection — every embedded
 * load keeps the background Admin API (sync, media push, webhooks) working. Fail-soft: a
 * failed exchange never blocks the panel from rendering.
 *
 * Session fixation: the id is regenerated on login. The shop_domain is stamped into the
 * session so the panel's frame-ancestors CSP can name the exact shop.
 */
final class EmbeddedSessionController
{
    // === CONSTANTS ===
    private const CFG_PANEL_PATH = 'shopify.merchant_panel_path';

    private const KEY_DASHBOARD_URL = 'dashboard_url';

    private const LOG_TOKEN_REFRESHED = 'shopify.token_exchange.refreshed';

    private const LOG_TOKEN_FAILED = 'shopify.token_exchange.failed';

    public function __invoke(Request $request): JsonResponse
    {
        $context = ShopifyEmbeddedContext::of($request);

        // Refresh the store's offline Admin-API token BEFORE anything else uses it.
        $this->refreshOfflineToken($request, $context);

        Auth::login($context->owner);
        $request->session()->regenerate();
        $request->session()->put(ShopifyFrameAncestors::SESSION_SHOP_DOMAIN, $context->shopDomain());

        $base = rtrim((string) config(self::CFG_PANEL_PATH), '/');

        return WidgetResponse::ok([
            self::KEY_DASHBOARD_URL => $base.'/'.$context->site->slug,
        ]);
    }

    /**
     * Token exchange: the verified App Bridge session token (the request Bearer) becomes an
     * EXPIRING offline access token, re-stored on the connection. Fail-soft — a failed
     * exchange logs and returns; the panel still renders (the old token retries next load).
     */
    private function refreshOfflineToken(Request $request, ShopifyEmbeddedContext $context): void
    {
        $sessionToken = (string) $request->bearerToken();

        if ($sessionToken === '') {
            return;
        }

        try {
            $token = app(ShopifyOAuth::class)->exchangeSessionToken($context->shopDomain(), $sessionToken);

            $connection = $context->connection;
            $connection->credentials = $token->toCredentials();
            $connection->needs_reauth = false;
            $connection->save();

            Log::info(self::LOG_TOKEN_REFRESHED, ['shop_domain' => $context->shopDomain()]);
        } catch (\Throwable $e) {
            // The store keeps its previous token; the refresh retries on the next embedded load.
            Log::warning(self::LOG_TOKEN_FAILED, ['shop_domain' => $context->shopDomain(), 'error' => $e::class]);
        }
    }
}
