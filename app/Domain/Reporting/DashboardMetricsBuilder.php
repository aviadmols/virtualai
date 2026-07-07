<?php

namespace App\Domain\Reporting;

use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;

/**
 * DashboardMetricsBuilder — runs the account-scoped queries that fill a
 * DashboardMetrics snapshot. The aggregation lives HERE, never in a Blade widget.
 *
 * Tenant-safety: every query goes through a model carrying BelongsToAccount, run
 * inside Tenant::run($account) so the global scope isolates it to one account. There
 * is NO withoutGlobalScopes() and NO bare cross-account read; a forgotten filter
 * fails CLOSED (returns nothing), never leaks. The account is passed EXPLICITLY,
 * never read from ambient state (the TS-TENANCY-001 rule).
 *
 * Low-balance threshold (the documented assumption): resolved PER-SITE with an
 * ACCOUNT-level fallback. Each site may set sites.usage_limits.low_balance_micro_usd;
 * the account's effective warn threshold is the MAX across its sites (the most
 * cautious site wins — warn as soon as the strictest site would), falling back to
 * config('trayon.credits.low_balance_micro_usd') when no site overrides it. The
 * exact gate (assertCanSpend) is unaffected; this is only the WARN line.
 */
final class DashboardMetricsBuilder
{
    // === CONSTANTS ===
    // Where a site may override the low-balance WARN threshold (micro-USD).
    private const SITE_LOW_BALANCE_KEY = 'low_balance_micro_usd';
    // The account-level fallback when no site sets an override.
    private const LOW_BALANCE_CONFIG_KEY = 'trayon.credits.low_balance_micro_usd';

    /**
     * Build the merchant-home snapshot for $account over $window. Time-bounded
     * figures (generations, leads in window) use $window; lifetime figures (sites,
     * products, total leads, balance) ignore it.
     *
     * When $site is given, the STORE-specific figures (products, try-ons, leads) are
     * scoped to that one store, so the Overview reflects the shop the merchant is in.
     * Account-level figures (site count, balance/reserved — credits are shared across a
     * store's sibling sites) always stay account-wide.
     */
    public function build(Account $account, ?MetricWindow $window = null, ?Site $site = null): DashboardMetrics
    {
        $window ??= MetricWindow::lastDays();
        $siteId = $site !== null ? (int) $site->getKey() : null;

        return Tenant::run($account, function () use ($account, $window, $siteId): DashboardMetrics {
            // Fresh account so the balance/reserved reflect the latest ledger writes
            // (an observer may have moved the column on the passed instance; see TS-CREDITS-001).
            $fresh = $account->fresh() ?? $account;

            $sitesCount = Site::query()->count();

            $productsTotal = $this->scopeSite(Product::query(), $siteId)->count();
            $productsConfirmed = $this->scopeSite(
                Product::query()->where('status', Product::STATUS_CONFIRMED),
                $siteId,
            )->count();

            $generationsInWindow = $this->countGenerations($window, null, $siteId);
            $succeeded = $this->countGenerations($window, Generation::STATUS_SUCCEEDED, $siteId);
            $failed = $this->countGenerations($window, Generation::STATUS_FAILED, $siteId);

            $leadsTotal = $this->scopeSite(EndUser::query(), $siteId)->count();
            $leadsRegistered = $this->scopeSite(EndUser::query()->whereNotNull('registered_at'), $siteId)->count();
            $leadsInWindow = $this->scopeSite($window->constrain(EndUser::query(), 'created_at'), $siteId)->count();

            $balance = (int) $fresh->balance_micro_usd;
            $reserved = (int) $fresh->reserved_micro_usd;
            $spendable = $balance - $reserved;

            return new DashboardMetrics(
                sitesCount: $sitesCount,
                productsTotal: $productsTotal,
                productsConfirmed: $productsConfirmed,
                generationsInWindow: $generationsInWindow,
                generationsSucceededInWindow: $succeeded,
                generationsFailedInWindow: $failed,
                successRate: $this->successRate($succeeded, $failed),
                balanceMicroUsd: $balance,
                reservedMicroUsd: $reserved,
                spendableMicroUsd: $spendable,
                isLowBalance: $spendable <= $this->lowBalanceThreshold(),
                leadsTotal: $leadsTotal,
                leadsRegistered: $leadsRegistered,
                leadsInWindow: $leadsInWindow,
                window: $window,
            );
        });
    }

    /** Count generations in the window, optionally filtered to one status + one store. */
    private function countGenerations(MetricWindow $window, ?string $status = null, ?int $siteId = null): int
    {
        $query = Generation::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return (int) $window->constrain($this->scopeSite($query, $siteId), 'created_at')->count();
    }

    /**
     * Narrow a tenant-scoped query to ONE store when a site id is given (else leave it
     * account-wide). Every model here carries BelongsToAccount, so this only ever tightens
     * an already account-isolated query — never widens it.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function scopeSite($query, ?int $siteId)
    {
        return $siteId !== null ? $query->where('site_id', $siteId) : $query;
    }

    /** Succeeded / (succeeded + failed) in [0,1]; 0.0 when no terminal attempts. */
    private function successRate(int $succeeded, int $failed): float
    {
        $terminal = $succeeded + $failed;

        if ($terminal === 0) {
            return 0.0;
        }

        return $succeeded / $terminal;
    }

    /**
     * The effective low-balance WARN threshold (micro-USD): the MAX per-site override
     * across the bound account's sites, else the config default. Runs inside the
     * already-bound tenant scope, so it only reads this account's sites.
     */
    private function lowBalanceThreshold(): int
    {
        $default = (int) config(self::LOW_BALANCE_CONFIG_KEY);

        $maxOverride = Site::query()
            ->get(['usage_limits'])
            ->map(fn (Site $site): ?int => $this->siteOverride($site))
            ->filter(fn (?int $value): bool => $value !== null)
            ->max();

        // The most cautious site wins; never below the account-level default.
        return $maxOverride === null ? $default : max((int) $maxOverride, $default);
    }

    /** A site's low-balance override (micro-USD) from usage_limits, or null. */
    private function siteOverride(Site $site): ?int
    {
        $limits = $site->usage_limits ?? [];

        $value = $limits[self::SITE_LOW_BALANCE_KEY] ?? null;

        return $value === null ? null : (int) $value;
    }
}
