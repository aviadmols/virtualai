<?php

use App\Http\Controllers\Credits\PurchaseWebhookController;
use Illuminate\Support\Facades\Route;

// === CONSTANTS ===
// Guarded so route:cache (which can evaluate route files more than once) stays idempotent.
defined('ROUTE_CREDITS_PURCHASE_WEBHOOK') || define('ROUTE_CREDITS_PURCHASE_WEBHOOK', 'webhooks.credits.purchase');

/*
 * Server-to-server payment webhooks. NO session, NO CSRF — the provider's SIGNATURE is
 * the auth (verified inside the controller via the resolved CreditPaymentProvider). The
 * {provider} segment selects the rail (payplus for v1). The handler is idempotent: a
 * replayed webhook credits the account at most once.
 */
Route::post('/webhooks/credits/{provider}', PurchaseWebhookController::class)
    ->name(ROUTE_CREDITS_PURCHASE_WEBHOOK);
