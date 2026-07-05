<?php

namespace App\Domain\Reporting;

/**
 * SiteHubMetrics — the immutable, per-SHOP snapshot the merchant Overview hub
 * (WS1) binds to. A frozen read-contract: the ViewSite hub reads these named
 * fields and NEVER aggregates in Blade.
 *
 * Built by SiteHubMetricsBuilder inside the shop's own bound tenant
 * (BelongsToAccount), narrowed to one site_id — so every count is isolated to the
 * shop's account by construction and to this shop by an explicit site filter.
 *
 * Money is integer micro-USD of selling value (the account's spendable balance);
 * the UI formats it. successRate is a ratio in [0,1]; the UI formats it too.
 */
final readonly class SiteHubMetrics
{
    // === CONSTANTS ===
    // The KPI keys the hub band maps to i18n labels (sites.hub.kpi.*). Stable
    // tokens, NOT i18n strings — the admin layer maps each to __(). Freezes the
    // contract the hub depends on.
    public const KPI_PRODUCTS_CONFIRMED = 'products_confirmed';
    public const KPI_GENERATIONS_WINDOW = 'generations_in_window';
    public const KPI_LEADS = 'leads_total';
    public const KPI_BALANCE = 'spendable_micro_usd';

    public function __construct(
        // --- Catalog footprint for this shop ---
        public int $productsConfirmed,

        // --- Try-on throughput over the window for this shop ---
        public int $generationsInWindow,
        public int $generationsSucceededInWindow,
        public int $generationsFailedInWindow,
        // Succeeded / (succeeded + failed) in [0,1]; 0.0 when no terminal attempts.
        public float $successRate,

        // --- Leads captured on this shop ---
        public int $leadsTotal,

        // --- Credits (account-level spendable; the shop shares its account's wallet) ---
        public int $spendableMicroUsd,
        public bool $isLowBalance,

        // --- The window the *InWindow figures were computed over (for the header) ---
        public MetricWindow $window,
    ) {}

    /** True when there were terminal attempts in the window (else "no data"). */
    public function hasGenerationData(): bool
    {
        return $this->generationsSucceededInWindow + $this->generationsFailedInWindow > 0;
    }
}
