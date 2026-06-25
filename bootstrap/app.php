<?php

use App\Http\Middleware\ResolveWidgetSite;
use App\Http\Middleware\WidgetRateLimit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Server-to-server webhooks (no session, no CSRF — signature-verified).
            Illuminate\Support\Facades\Route::middleware('throttle:webhooks')
                ->group(__DIR__.'/../routes/webhooks.php');

            // The signed widget API (Phase 7a). Stateless: no session, no CSRF — the auth
            // is site_key + Origin allow-list (+ optional HMAC), resolved by ResolveWidgetSite
            // which binds the tenant for the request lifecycle. WidgetRateLimit applies the
            // per-account/per-site request caps. Every route lives under /widget/v1.
            Illuminate\Support\Facades\Route::prefix(config('widget.prefix'))
                ->middleware([ResolveWidgetSite::class, WidgetRateLimit::class])
                ->group(__DIR__.'/../routes/widget.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The widget API must answer TYPED JSON, never an HTML error page, on any
        // unhandled throwable (validation/not-found/etc are already typed; this is the
        // backstop so a 500 never renders HTML to the storefront widget).
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is(config('widget.prefix').'/*') || $request->expectsJson();
        });
    })->create();
