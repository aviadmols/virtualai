<?php

namespace App\Domain\Reporting;

/**
 * DashboardMetrics — the immutable, account-scoped snapshot the MERCHANT home +
 * KPI widgets (component-inventory A1 / A6 / A11) bind to. A frozen read-contract:
 * downstream Filament widgets read these named fields and NEVER aggregate in Blade.
 *
 * Built by DashboardMetricsBuilder under a bound tenant (BelongsToAccount), so every
 * count/sum is already isolated to one account — there is no account_id on this DTO
 * because it is only ever constructed for the currently-bound tenant.
 *
 * Money is integer micro-USD of selling value everywhere (balance/reserved/spendable),
 * matching accounts.*_micro_usd. successRate is a ratio in [0,1]; the UI formats it.
 */
final readonly class DashboardMetrics
{
    // === CONSTANTS ===
    // The KPI keys the A1 cards map to i18n labels (dashboard.kpi.*). Stable tokens,
    // NOT i18n strings — the admin layer maps each to __(). A widget binds to a field
    // below by these names; this list freezes the contract the dashboard depends on.
    public const KPI_SITES = 'sites';
    public const KPI_PRODUCTS_CONFIRMED = 'products_confirmed';
    public const KPI_GENERATIONS_WINDOW = 'generations_in_window';
    public const KPI_SUCCESS_RATE = 'success_rate';
    public const KPI_BALANCE = 'spendable_micro_usd';
    public const KPI_LEADS = 'leads_total';

    public function __construct(
        // --- Catalog footprint (account lifetime) ---
        public int $sitesCount,
        public int $productsTotal,
        public int $productsConfirmed,

        // --- Generation throughput over the window (A1 "generations") ---
        public int $generationsInWindow,
        public int $generationsSucceededInWindow,
        public int $generationsFailedInWindow,
        // Succeeded / (succeeded + failed) in [0,1]; 0.0 when there were no terminal
        // attempts in the window (avoids a divide-by-zero and reads as "no data").
        public float $successRate,

        // --- Credits (A1 balance KPI + A10 low-credit banner) ---
        // All integer micro-USD of selling value. spendable = balance − reserved.
        public int $balanceMicroUsd,
        public int $reservedMicroUsd,
        public int $spendableMicroUsd,
        // True when spendable has dropped to/below the low-balance threshold (config /
        // per-site override). Drives the A10 warn banner; the danger banner is spendable<=0.
        public bool $isLowBalance,

        // --- Leads (A1 leads KPI + A6 leads table headline) ---
        public int $leadsTotal,
        public int $leadsRegistered,
        public int $leadsInWindow,

        // --- The window these *InWindow figures were computed over (for the UI header) ---
        public MetricWindow $window,
    ) {}

    /** The succeeded-share of terminal attempts as a ratio in [0,1] (0 = no data). */
    public function hasGenerationData(): bool
    {
        return $this->generationsSucceededInWindow + $this->generationsFailedInWindow > 0;
    }

    /** True when the account has zero spendable credit (A10 danger / persistent banner). */
    public function isOutOfCredits(): bool
    {
        return $this->spendableMicroUsd <= 0;
    }
}
