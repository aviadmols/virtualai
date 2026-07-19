<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// === CONSTANTS ===
// Guarded so route:cache (which can evaluate route files more than once) stays idempotent.
defined('ROUTE_HEALTH') || define('ROUTE_HEALTH', 'health');
// Root sends a visitor INTO the system: the merchant panel. Filament bounces a guest to its
// own login screen (/merchant/login), and an authenticated merchant to their dashboard.
defined('PATH_ROOT_REDIRECT') || define('PATH_ROOT_REDIRECT', '/merchant');

Route::get('/', fn () => redirect()->to(PATH_ROOT_REDIRECT));

// Liveness/readiness: app + DB + Redis + queue reachability + scheduler heartbeat.
// Laravel's built-in /up (bootstrap/app.php) stays for the platform healthcheck;
// /health is the richer operator surface.
Route::get('/'.ROUTE_HEALTH, HealthController::class)->name(ROUTE_HEALTH);

// Media served from a LOCAL disk (a Railway Volume). Inert unless MEDIA_DISK is a volume — the
// object-store path never routes here. Public banner objects (cacheable) + expiring signed URLs.
Route::get('/media/pub/{path}', [MediaController::class, 'public'])->where('path', '.*')->name('media.public');
Route::get('/media/sig', [MediaController::class, 'signed'])->name('media.signed');
