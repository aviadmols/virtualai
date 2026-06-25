<?php

namespace App\Providers;

use App\Domain\Credits\Payments\CreditProviderResolver;
use App\Domain\Credits\Payments\PayPlusProvider;
use App\Domain\Credits\ReservationManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * CreditsServiceProvider — wires the credit/money domain: the reservation cache, the
 * swappable payment-provider rail, and the webhook rate-limit (the SHAPE + numbers are
 * ours; railway-infra owns the Redis bucket backing it).
 *
 * The ReservationManager needs a cache repository for its short-lived in-flight
 * reservation key (Redis in production, the array store in tests). The
 * CreditProviderResolver is built from the registered providers keyed by name; v1 has
 * the single LOCKED PayPlus rail, but every call site depends on the resolver/interface
 * so a future Stripe rail is a one-line binding swap.
 */
class CreditsServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    // Webhook limiter (provider retries are bursty but bounded). railway-infra may raise
    // these against the production load model; this is the safe default + the 429 shape.
    private const WEBHOOK_RATE_LIMITER = 'webhooks';
    private const WEBHOOK_RPM = 120;

    public function register(): void
    {
        $this->app->singleton(ReservationManager::class, fn (): ReservationManager => new ReservationManager(
            $this->reservationCache(),
        ));

        // The PayPlus rail (LOCKED v1). Registered by name so the resolver + the webhook
        // route can select it; adding Stripe later is another entry in this map.
        $this->app->singleton(PayPlusProvider::class);

        $this->app->singleton(CreditProviderResolver::class, fn ($app): CreditProviderResolver => new CreditProviderResolver([
            PayPlusProvider::PROVIDER_NAME => $app->make(PayPlusProvider::class),
        ]));
    }

    public function boot(): void
    {
        // The webhook throttle: a typed 429 with Retry-After on burst, never a 500/drop.
        RateLimiter::for(self::WEBHOOK_RATE_LIMITER, fn (Request $request): Limit => Limit::perMinute(self::WEBHOOK_RPM)
            ->by($request->ip() ?: 'webhook'));
    }

    /** The cache repository backing the in-flight reservation key (default store). */
    private function reservationCache(): CacheRepository
    {
        return Cache::store();
    }
}
