<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WidgetCorsPreflight — answers the CORS preflight (OPTIONS) for the widget API.
 *
 * The storefront widget calls /widget/v1/* cross-origin with a custom header
 * (X-Tray-Site-Key), so the browser sends an OPTIONS preflight first. Laravel's synthetic
 * auto-OPTIONS response skips the route-group middleware (ResolveWidgetSite), so the
 * preflight would carry NO CORS headers and the browser blocks the whole call (the bug a
 * real store hit). This GLOBAL middleware answers the preflight directly: it echoes the
 * requesting Origin + the allowed methods/headers (a 204).
 *
 * It authorizes NOTHING — the ACTUAL request is still fully gated by ResolveWidgetSite
 * (site_key + per-site Origin allow-list), which sets the real per-origin CORS on the
 * response and 403s a disallowed origin. The permissive preflight only lets the browser
 * proceed to that authenticated request.
 */
final class WidgetCorsPreflight
{
    // === CONSTANTS ===
    private const ALLOW_METHODS = 'GET, POST, OPTIONS';
    private const ALLOW_HEADERS = 'Content-Type, X-Tray-Site-Key, X-Tray-Signature, X-Tray-Timestamp';
    private const MAX_AGE = '600';
    private const PREFIX_CONFIG = 'widget.prefix';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') && $request->is(((string) config(self::PREFIX_CONFIG)).'/*')) {
            $origin = (string) $request->header('Origin', '');
            $response = response()->noContent();

            if ($origin !== '') {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Vary', 'Origin');
                $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
                $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
                $response->headers->set('Access-Control-Max-Age', self::MAX_AGE);
            }

            return $response;
        }

        return $next($request);
    }
}
