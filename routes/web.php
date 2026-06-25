<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// === CONSTANTS ===
// Guarded so route:cache (which can evaluate route files more than once) stays idempotent.
defined('ROUTE_HEALTH') || define('ROUTE_HEALTH', 'health');

Route::get('/', function () {
    return view('welcome');
});

// Liveness/readiness: app + DB + Redis + queue reachability + scheduler heartbeat.
// Laravel's built-in /up (bootstrap/app.php) stays for the platform healthcheck;
// /health is the richer operator surface.
Route::get('/'.ROUTE_HEALTH, HealthController::class)->name(ROUTE_HEALTH);
