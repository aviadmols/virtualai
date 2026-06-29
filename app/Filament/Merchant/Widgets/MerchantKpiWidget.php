<?php

namespace App\Filament\Merchant\Widgets;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DashboardMetricsBuilder;
use App\Models\Account;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * M1 / A1 — the merchant KPI band. Reads the typed DashboardMetrics snapshot
 * (built account-scoped by DashboardMetricsBuilder) and hands each card a
 * PRE-FORMATTED value — this widget NEVER aggregates a number in Blade.
 *
 * The account is resolved from the authenticated account-owner (auth()->user()
 * ->account), never another account; the builder runs every query inside that
 * bound tenant (BelongsToAccount), so the figures are isolated by construction.
 */
class MerchantKpiWidget extends Widget
{
    // === CONSTANTS ===
    protected static string $view = 'filament.merchant.widgets.merchant-kpi';

    // Full width of the dashboard grid; the cards self-arrange in the A1 band.
    protected int|string|array $columnSpan = 'full';

    // The card i18n labels (dashboard.kpi.*), one per DashboardMetrics field.
    private const LABEL_SITES = 'dashboard.kpi.sites';
    private const LABEL_PRODUCTS = 'dashboard.kpi.products';
    private const LABEL_GENERATIONS = 'dashboard.kpi.generations';
    private const LABEL_SUCCESS_RATE = 'dashboard.kpi.success_rate';
    private const LABEL_BALANCE = 'dashboard.kpi.balance';
    private const LABEL_LEADS = 'dashboard.kpi.leads';

    // Tone per card (StatusBadge vocabulary → the KPI accent edge).
    private const TONE_NEUTRAL = 'neutral';
    private const TONE_INFO = 'info';
    private const TONE_SUCCESS = 'success';

    /**
     * Build the six A1 cards as a flat, render-ready array. Each entry carries
     * its i18n label key, a pre-formatted value string, and a tone.
     *
     * @return array<int,array{label:string,value:string,tone:string}>
     */
    public function getCards(): array
    {
        $metrics = $this->metrics();

        return [
            [
                'label' => self::LABEL_SITES,
                'value' => $this->int($metrics->sitesCount),
                'tone' => self::TONE_NEUTRAL,
            ],
            [
                'label' => self::LABEL_PRODUCTS,
                'value' => $this->int($metrics->productsConfirmed),
                'tone' => self::TONE_NEUTRAL,
            ],
            [
                'label' => self::LABEL_GENERATIONS,
                'value' => $this->int($metrics->generationsInWindow),
                'tone' => self::TONE_INFO,
            ],
            [
                'label' => self::LABEL_SUCCESS_RATE,
                'value' => $this->percent($metrics->successRate, $metrics->hasGenerationData()),
                'tone' => self::TONE_SUCCESS,
            ],
            [
                'label' => self::LABEL_BALANCE,
                'value' => $this->usd($metrics->spendableMicroUsd),
                'tone' => $metrics->isLowBalance ? 'warn' : self::TONE_SUCCESS,
            ],
            [
                'label' => self::LABEL_LEADS,
                'value' => $this->int($metrics->leadsTotal),
                'tone' => self::TONE_INFO,
            ],
        ];
    }

    /** The account-scoped snapshot for the signed-in merchant. */
    private function metrics(): DashboardMetrics
    {
        return app(DashboardMetricsBuilder::class)->build($this->account());
    }

    /** The authenticated account-owner's account (never another account). */
    private function account(): Account
    {
        return Auth::user()->account;
    }

    /** Locale-aware integer formatting (display only — no aggregation here). */
    private function int(int $value): string
    {
        return number_format($value);
    }

    /** A ratio in [0,1] → a whole-percent string; em-dash when there is no data. */
    private function percent(float $ratio, bool $hasData): string
    {
        if (! $hasData) {
            return '—';
        }

        return number_format($ratio * 100, 0).'%';
    }

    /** Integer micro-USD of selling value → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }
}
