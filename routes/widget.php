<?php

use App\Http\Controllers\Widget\AddToCartEventController;
use App\Http\Controllers\Widget\BannerEventController;
use App\Http\Controllers\Widget\BootstrapController;
use App\Http\Controllers\Widget\ClubRequestCodeController;
use App\Http\Controllers\Widget\ClubVerifyCodeController;
use App\Http\Controllers\Widget\EventController;
use App\Http\Controllers\Widget\GalleryController;
use App\Http\Controllers\Widget\GenerationController;
use App\Http\Controllers\Widget\LeadController;
use Illuminate\Support\Facades\Route;

// === CONSTANTS ===
// Stable route names the widget client + tests reference. Guarded define()s so route:cache
// (which can evaluate route files more than once) stays idempotent (TS-INFRA-003).
defined('ROUTE_WIDGET_BOOTSTRAP') || define('ROUTE_WIDGET_BOOTSTRAP', 'widget.v1.bootstrap');
defined('ROUTE_WIDGET_GEN_STORE') || define('ROUTE_WIDGET_GEN_STORE', 'widget.v1.generations.store');
defined('ROUTE_WIDGET_GEN_SHOW') || define('ROUTE_WIDGET_GEN_SHOW', 'widget.v1.generations.show');
defined('ROUTE_WIDGET_LEADS') || define('ROUTE_WIDGET_LEADS', 'widget.v1.leads');
defined('ROUTE_WIDGET_GALLERY') || define('ROUTE_WIDGET_GALLERY', 'widget.v1.gallery');
defined('ROUTE_WIDGET_ADD_TO_CART') || define('ROUTE_WIDGET_ADD_TO_CART', 'widget.v1.events.add_to_cart');
defined('ROUTE_WIDGET_EVENTS') || define('ROUTE_WIDGET_EVENTS', 'widget.v1.events');
defined('ROUTE_WIDGET_CLUB_REQUEST_CODE') || define('ROUTE_WIDGET_CLUB_REQUEST_CODE', 'widget.v1.club.request_code');
defined('ROUTE_WIDGET_CLUB_VERIFY_CODE') || define('ROUTE_WIDGET_CLUB_VERIFY_CODE', 'widget.v1.club.verify_code');
defined('ROUTE_WIDGET_BANNER_EVENT') || define('ROUTE_WIDGET_BANNER_EVENT', 'widget.v1.banners.event');

/*
 * The signed widget API (Phase 7a). Every route is behind the widget-auth middleware
 * (ResolveWidgetSite: site_key + Origin allow-list + optional HMAC -> binds the tenant)
 * and the per-account/per-site rate limiter (WidgetRateLimit). The middleware group +
 * the /widget/v1 prefix are applied where this file is registered (bootstrap/app.php).
 * Stateless: no session, no CSRF — the auth is site_key + Origin (+ optional HMAC).
 */

// Public config the widget boots from (confirmed product, selectors, lead state, locale).
Route::get('/bootstrap', BootstrapController::class)->name(ROUTE_WIDGET_BOOTSTRAP);

// Start a try-on (gated, idempotent) + poll its status/result.
Route::post('/generations', [GenerationController::class, 'store'])->name(ROUTE_WIDGET_GEN_STORE);
Route::get('/generations/{id}', [GenerationController::class, 'show'])
    ->whereNumber('id')
    ->name(ROUTE_WIDGET_GEN_SHOW);

// Signup (lead capture) — re-opens the LeadGate.
Route::post('/leads', LeadController::class)->name(ROUTE_WIDGET_LEADS);

// The end-user's session gallery (signed result URLs).
Route::get('/gallery', GalleryController::class)->name(ROUTE_WIDGET_GALLERY);

// Add-to-cart funnel event (the real cart add is the host platform's job).
Route::post('/events/add-to-cart', AddToCartEventController::class)->name(ROUTE_WIDGET_ADD_TO_CART);

// Behavioral events ingest (page views + interactions) — fire-and-forget, always { ok:true }.
Route::post('/events', EventController::class)->name(ROUTE_WIDGET_EVENTS);

// Per-banner impression/click analytics — fire-and-forget, always { ok:true }.
Route::post('/banners/event', BannerEventController::class)->name(ROUTE_WIDGET_BANNER_EVENT);

// Customer-Club email one-time-code (Phase 2a): request a code, then verify it to
// become a member. Both always typed { ok:true, ... } (never a 500/HTML).
Route::post('/club/request-code', ClubRequestCodeController::class)->name(ROUTE_WIDGET_CLUB_REQUEST_CODE);
Route::post('/club/verify-code', ClubVerifyCodeController::class)->name(ROUTE_WIDGET_CLUB_VERIFY_CODE);
