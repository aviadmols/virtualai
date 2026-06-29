<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\CostsMetrics;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Models\Account;
use Filament\Widgets\Widget;

/**
 * P1 / A1 — the platform KPI band. Renders the PLATFORM-WIDE costs snapshot
 * (CostsMetricsBuilder) as pre-formatted A1 cards. This widget NEVER aggregates
 * a number in Blade — every figure is computed by the typed builder/DTO and only
 * formatted here for display.
 *
 * Reachable only from the platform panel (super-admin gated by canAccessPanel).
 * CostsMetricsBuilder is the ONE sanctioned cross-account aggregate (sums/counts
 * only — no row, no PII). The active-account count is a plain global read of the
 * tenant-root Account, which is NOT BelongsToAccount and reads globally — so it
 * needs no seam (only BelongsToAccount models do).
 */
class PlatformKpiWidget extends Widget
{
    // === CONSTANTS ===
    protected static string $view = 'filament.platform.widgets.platform-kpi';

    // Full width; the cards self-arrange in the A1 auto-fit band.
    protected int|string|array $columnSpan = 'full';

    // KPI i18n label keys (platform.costs.kpi.*), one per card.
    private const LABEL_REVENUE = 'platform.costs.kpi.revenue';
    private const LABEL_COST = 'platform.costs.kpi.cost';
    private const LABEL_MARGIN = 'platform.costs.kpi.margin';
    private const LABEL_MARKUP = 'platform.costs.kpi.markup';
    private const LABEL_CHARGES = 'platform.costs.kpi.charges';
    private const LABEL_ACCOUNTS = 'platform.costs.kpi.accounts';

    // Tone per card (StatusBadge vocabulary → the KPI accent edge).
    private const TONE_SUCCESS = 'success';
    private const TONE_INFO = 'info';
    private const TONE_NEUTRAL = 'neutral';
    private const TONE_DANGER = 'danger';

    // No-data placeholder for a ratio with no recorded cost.
    private const EMPTY_VALUE = '—';

    /**
     * Build the six A1 cards as a flat, render-ready array. Each entry carries an
     * i18n label key, a pre-formatted value string, and a tone.
     *
     * @return array<int,array{label:string,value:string,tone:string}>
     */
    public function getCards(): array
    {
        $metrics = $this->metrics();

        return [
            [
                'label' => self::LABEL_REVENUE,
                'value' => $this->usd($metrics->revenueMicroUsd),
                'tone' => self::TONE_SUCCESS,
            ],
            [
                'label' => self::LABEL_COST,
                'value' => $this->usd($metrics->actualCostMicroUsd),
                'tone' => self::TONE_NEUTRAL,
            ],
            [
                'label' => self::LABEL_MARGIN,
                'value' => $this->usd($metrics->grossMarginMicroUsd),
                'tone' => $metrics->grossMarginMicroUsd >= 0 ? self::TONE_SUCCESS : self::TONE_DANGER,
            ],
            [
                'label' => self::LABEL_MARKUP,
                'value' => $this->markup($metrics),
                'tone' => $this->markupTone($metrics),
            ],
            [
                'label' => self::LABEL_CHARGES,
                'value' => $this->int($metrics->chargeCount),
                'tone' => self::TONE_INFO,
            ],
            [
                'label' => self::LABEL_ACCOUNTS,
                'value' => $this->int($this->activeAccounts()),
                'tone' => self::TONE_INFO,
            ],
        ];
    }

    /** The platform-wide costs snapshot over the default 30-day window. */
    private function metrics(): CostsMetrics
    {
        return app(CostsMetricsBuilder::class)->build(MetricWindow::lastDays());
    }

    /** Active (non-suspended) account count — Account is the tenant root, read globally. */
    private function activeAccounts(): int
    {
        return Account::query()->where('status', Account::STATUS_ACTIVE)->count();
    }

    /** Integer micro-USD of selling value → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }

    /** Locale-aware integer formatting (display only — no aggregation here). */
    private function int(int $value): string
    {
        return number_format($value);
    }

    /** Realized markup as a ×N.NN string; em-dash when there is no cost data. */
    private function markup(CostsMetrics $metrics): string
    {
        if (! $metrics->hasCostData()) {
            return self::EMPTY_VALUE;
        }

        return number_format($metrics->markupRealized, 2).'×';
    }

    /** Realized markup tone: success at/above target, warn below, neutral when no data. */
    private function markupTone(CostsMetrics $metrics): string
    {
        if (! $metrics->hasCostData()) {
            return self::TONE_NEUTRAL;
        }

        return $metrics->markupRealized >= $metrics->markupTarget
            ? self::TONE_SUCCESS
            : 'warn';
    }
}
