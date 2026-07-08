<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Filament\Platform\Concerns\ResolvesReportWindow;
use Filament\Widgets\Widget;

/**
 * P1 — the costs-vs-revenue SUMMARY card. A designed panel (not a chart lib) that
 * lays revenue billed against the real OpenRouter cost, with the realized markup
 * compared to the configured target. Every figure is PRE-FORMATTED here from the
 * typed CostsMetrics DTO — no aggregation in Blade; the builder is the one place
 * the cross-account sums are computed.
 */
class CostsVsRevenueWidget extends Widget
{
    use ResolvesReportWindow;

    // === CONSTANTS ===
    protected static string $view = 'filament.platform.widgets.costs-vs-revenue';

    protected int|string|array $columnSpan = 'full';

    private const EMPTY_VALUE = '—';

    /**
     * The render-ready summary the Blade view consumes. Pure presentation strings
     * + booleans for state; never a raw model or an aggregation.
     *
     * @return array{
     *   hasData:bool, window:int,
     *   revenue:string, cost:string, margin:string,
     *   marginNegative:bool, marginRatio:string,
     *   markupRealized:string, markupTarget:string, onTarget:bool,
     *   barRevenue:int, barCost:int, barMargin:int
     * }
     */
    public function getSummary(): array
    {
        $metrics = app(CostsMetricsBuilder::class)->build($this->reportWindow());
        $revenue = $metrics->revenueMicroUsd;

        return [
            'hasData' => $metrics->chargeCount > 0,
            'window' => $this->reportWindowDays(),
            'revenue' => $this->usd($metrics->revenueMicroUsd),
            'cost' => $this->usd($metrics->actualCostMicroUsd),
            'margin' => $this->usd($metrics->grossMarginMicroUsd),
            'marginNegative' => $metrics->grossMarginMicroUsd < 0,
            'marginRatio' => $this->percent($metrics->marginRatio()),
            'markupRealized' => $metrics->hasCostData()
                ? number_format($metrics->markupRealized, 2)
                : self::EMPTY_VALUE,
            'markupTarget' => number_format($metrics->markupTarget, 1),
            'onTarget' => $metrics->hasCostData() && $metrics->markupRealized >= $metrics->markupTarget,
            // Bar widths as whole-percent integers of revenue, for the CSS gauge
            // (the component reads these via a CSS custom property the Blade sets
            // through a data-attribute — no inline style, no value literal).
            'barRevenue' => 100,
            'barCost' => $this->shareOf($metrics->actualCostMicroUsd, $revenue),
            'barMargin' => $this->shareOf(max(0, $metrics->grossMarginMicroUsd), $revenue),
        ];
    }

    /** Integer micro-USD → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }

    /** A ratio in [0,1] → a whole-percent integer string. */
    private function percent(float $ratio): string
    {
        return number_format($ratio * 100, 0);
    }

    /** A part as a whole-percent of a whole (0 when the whole is 0), clamped to 100. */
    private function shareOf(int $part, int $whole): int
    {
        if ($whole <= 0) {
            return 0;
        }

        return (int) min(100, round($part / $whole * 100));
    }
}
