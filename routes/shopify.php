<?php

use App\Http\Shopify\Controllers\InstallClaimController;
use App\Http\Shopify\Controllers\OAuthController;
use App\Http\Shopify\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// === CONSTANTS ===
// Guarded so route:cache (which can evaluate route files more than once) stays idempotent.
defined('ROUTE_SHOPIFY_WEBHOOKS') || define('ROUTE_SHOPIFY_WEBHOOKS', 'shopify.webhooks');
defined('ROUTE_SHOPIFY_INSTALL') || define('ROUTE_SHOPIFY_INSTALL', 'shopify.install');
defined('ROUTE_SHOPIFY_INSTALL_CLAIM') || define('ROUTE_SHOPIFY_INSTALL_CLAIM', 'shopify.install.claim');
defined('ROUTE_SHOPIFY_OAUTH_START') || define('ROUTE_SHOPIFY_OAUTH_START', 'shopify.oauth.start');
defined('ROUTE_SHOPIFY_OAUTH_CALLBACK') || define('ROUTE_SHOPIFY_OAUTH_CALLBACK', 'shopify.oauth.callback');

defined('PATH_SHOPIFY_WEBHOOKS') || define('PATH_SHOPIFY_WEBHOOKS', '/shopify/webhooks');
defined('PATH_SHOPIFY_INSTALL') || define('PATH_SHOPIFY_INSTALL', '/shopify/install');
defined('PATH_SHOPIFY_INSTALL_CLAIM') || define('PATH_SHOPIFY_INSTALL_CLAIM', '/shopify/install/claim');
defined('PATH_SHOPIFY_OAUTH_START') || define('PATH_SHOPIFY_OAUTH_START', '/shopify/oauth/start');
defined('PATH_SHOPIFY_OAUTH_CALLBACK') || define('PATH_SHOPIFY_OAUTH_CALLBACK', '/shopify/oauth/callback');

// The webhook intake is server-to-server: throttled, but NO session and NO CSRF —
// Shopify's raw-body HMAC is the auth (verified inside the controller, fails closed).
defined('MW_SHOPIFY_WEBHOOK') || define('MW_SHOPIFY_WEBHOOK', ['throttle:webhooks']);

// The OAuth + claim routes are top-level browser redirects: they need the `web` stack
// (session/cookies) so the signed state, the pending-install claim token, and the
// post-login intended URL survive the round trip through Shopify's grant screen.
// They are all GET, so CSRF never applies; the security is HMAC + the signed state.
defined('MW_SHOPIFY_WEB') || define('MW_SHOPIFY_WEB', ['web']);

Route::middleware(MW_SHOPIFY_WEBHOOK)
    ->post(PATH_SHOPIFY_WEBHOOKS, WebhookController::class)
    ->name(ROUTE_SHOPIFY_WEBHOOKS);

Route::middleware(MW_SHOPIFY_WEB)->group(function (): void {
    // install_new_shop — the entry Shopify itself sends the merchant to.
    Route::get(PATH_SHOPIFY_INSTALL, [OAuthController::class, 'install'])
        ->name(ROUTE_SHOPIFY_INSTALL);

    // connect_existing_site — the "Connect" click inside the Vsio merchant panel.
    Route::get(PATH_SHOPIFY_OAUTH_START, [OAuthController::class, 'start'])
        ->name(ROUTE_SHOPIFY_OAUTH_START);

    // The shared OAuth callback (both flows).
    Route::get(PATH_SHOPIFY_OAUTH_CALLBACK, [OAuthController::class, 'callback'])
        ->name(ROUTE_SHOPIFY_OAUTH_CALLBACK);

    // An authenticated account consumes its parked install exactly once.
    Route::get(PATH_SHOPIFY_INSTALL_CLAIM, InstallClaimController::class)
        ->name(ROUTE_SHOPIFY_INSTALL_CLAIM);
});
