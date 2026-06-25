<?php

namespace App\Domain\Credits;

use App\Models\Account;
use App\Models\Site;
use Illuminate\Support\Facades\RateLimiter;

/**
 * UsageGate — per-account + per-site usage limits and optional plan-feature gates.
 * Returns a typed GateDenied (never a 500). It COMPOSES with CreditGate (merchant
 * credits) and LeadGate (end-user free tries) — all independent; on the widget API a
 * single generation may be checked by all three.
 *
 * Two rate buckets back the widget generate path (railway-infra provisions the Redis
 * RateLimiter; saas-credits-billing owns the keys + the numbers + the 429 shape):
 *  - per (account, site): a generations-per-minute cap so one site's spike can't drain
 *    another site's responsiveness. Overridable via sites.usage_limits.widget_rpm.
 *  - per account: a generations-per-minute ceiling across ALL the account's sites (an
 *    abuse + cost ceiling).
 *
 * A hit returns GateDenied::rateLimited(...) carrying Retry-After; the widget API turns
 * that into HTTP 429. assertWithin()/assertFeature() cover the optional plan limits.
 *
 * Usage: UsageGate::for($account)->checkWidgetGenerate($site)
 */
final class UsageGate
{
    // === CONSTANTS ===
    private const LIMIT_SITE_WIDGET = 'widget_generate_site';
    private const LIMIT_ACCOUNT_GEN = 'generate_account';

    private const SITE_RPM_CONFIG_KEY = 'trayon.usage.site_widget_rpm';
    private const ACCOUNT_RPM_CONFIG_KEY = 'trayon.usage.account_gen_rpm';

    // Per-site override key inside sites.usage_limits JSON.
    private const SITE_OVERRIDE_RPM_KEY = 'widget_rpm';

    private const ONE_MINUTE = 60;

    private function __construct(
        private readonly Account $account,
    ) {}

    public static function for(Account $account): self
    {
        return new self($account);
    }

    /**
     * Check the widget "generate" action for a site: BOTH the per-(account,site) cap and
     * the per-account ceiling. The first hit returns a typed rate-limit denial with
     * Retry-After. Passing consumes one token in each bucket.
     */
    public function checkWidgetGenerate(Site $site): GateDenied
    {
        $siteRpm = $this->siteRpm($site);
        $siteKey = $this->siteBucketKey($site);

        if (RateLimiter::tooManyAttempts($siteKey, $siteRpm)) {
            return GateDenied::rateLimited(self::LIMIT_SITE_WIDGET, RateLimiter::availableIn($siteKey));
        }

        $accountRpm = (int) config(self::ACCOUNT_RPM_CONFIG_KEY);
        $accountKey = $this->accountBucketKey();

        if (RateLimiter::tooManyAttempts($accountKey, $accountRpm)) {
            return GateDenied::rateLimited(self::LIMIT_ACCOUNT_GEN, RateLimiter::availableIn($accountKey));
        }

        // Under both caps — consume a token in each (decay 60s = per-minute).
        RateLimiter::hit($siteKey, self::ONE_MINUTE);
        RateLimiter::hit($accountKey, self::ONE_MINUTE);

        return GateDenied::allow();
    }

    /**
     * A countable plan limit (e.g. max_sites): allowed while $current < $max. A null max
     * means unlimited. Returns GateDenied::planLimit on breach.
     */
    public function assertWithin(string $limitKey, int $current, ?int $max): GateDenied
    {
        if ($max === null) {
            return GateDenied::allow();
        }

        return $current < $max ? GateDenied::allow() : GateDenied::planLimit($limitKey);
    }

    /** A boolean plan feature (e.g. custom_branding): allowed iff enabled. */
    public function assertFeature(string $featureKey, bool $enabled): GateDenied
    {
        return $enabled ? GateDenied::allow() : GateDenied::planFeature($featureKey);
    }

    // === Internals ===

    /** The per-site RPM: a site override (usage_limits.widget_rpm) else the config default. */
    private function siteRpm(Site $site): int
    {
        $override = $site->usage_limits[self::SITE_OVERRIDE_RPM_KEY] ?? null;

        return $override !== null ? (int) $override : (int) config(self::SITE_RPM_CONFIG_KEY);
    }

    /** Bucket key scoped to (account, site) — isolation by account_id is in the key. */
    private function siteBucketKey(Site $site): string
    {
        return self::LIMIT_SITE_WIDGET.':'.$this->account->getKey().':'.$site->getKey();
    }

    /** Bucket key scoped to the account (across all its sites). */
    private function accountBucketKey(): string
    {
        return self::LIMIT_ACCOUNT_GEN.':'.$this->account->getKey();
    }
}
