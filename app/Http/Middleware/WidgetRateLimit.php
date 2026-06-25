<?php

namespace App\Http\Middleware;

use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * WidgetRateLimit — per-account AND per-site request-rate caps on EVERY widget route.
 * Runs AFTER ResolveWidgetSite (the tenant is bound, the WidgetContext is resolved), so
 * the buckets are keyed by the SERVER-resolved account/site, never a client-supplied id.
 *
 * Two independent buckets (the first hit wins):
 *  - per (account, site): one site's spike can't drain another's responsiveness.
 *  - per account: a ceiling across all the account's sites (abuse + cost guard).
 *
 * A hit returns a typed 429 with Retry-After (never a 500/HTML). railway-infra owns the
 * NUMBERS (config widget.rate.*) + the Redis bucket; this middleware reads them. The
 * heavier generate path ALSO passes the UsageGate (generations/min) inside the controller.
 */
final class WidgetRateLimit
{
    // === CONSTANTS ===
    private const SITE_RPM_CONFIG = 'widget.rate.site_rpm';
    private const ACCOUNT_RPM_CONFIG = 'widget.rate.account_rpm';

    private const BUCKET_SITE = 'widget_req_site';
    private const BUCKET_ACCOUNT = 'widget_req_account';

    private const ONE_MINUTE = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $context = WidgetContext::of($request);

        $siteRpm = (int) config(self::SITE_RPM_CONFIG);
        $siteKey = self::BUCKET_SITE.':'.$context->accountId().':'.$context->siteId();

        if (RateLimiter::tooManyAttempts($siteKey, $siteRpm)) {
            return $this->tooMany(RateLimiter::availableIn($siteKey));
        }

        $accountRpm = (int) config(self::ACCOUNT_RPM_CONFIG);
        $accountKey = self::BUCKET_ACCOUNT.':'.$context->accountId();

        if (RateLimiter::tooManyAttempts($accountKey, $accountRpm)) {
            return $this->tooMany(RateLimiter::availableIn($accountKey));
        }

        // Under both caps — consume a token in each (decay 60s = per-minute).
        RateLimiter::hit($siteKey, self::ONE_MINUTE);
        RateLimiter::hit($accountKey, self::ONE_MINUTE);

        return $next($request);
    }

    /** A typed 429 carrying Retry-After. Never a 500. */
    private function tooMany(int $retryAfterSeconds): Response
    {
        $retryAfter = max(1, $retryAfterSeconds);

        $response = WidgetResponse::gate(
            'rate_limited',
            __('widget_api.rate_limited'),
            WidgetResponse::STATUS_TOO_MANY,
            ['retry_after' => $retryAfter],
        );

        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
