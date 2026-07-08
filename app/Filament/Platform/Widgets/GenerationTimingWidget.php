<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Reporting\CostsMetricsBuilder;
use App\Filament\Platform\Concerns\ResolvesReportWindow;
use Filament\Widgets\ChartWidget;

/**
 * P1 — try-on generation timing. A line chart of the AVERAGE provider render time (ms) per day over
 * the selected window, so the Super-Admin sees how long each try-on takes to generate + the trend.
 * The per-day averages are computed by CostsMetricsBuilder::generationTimings (from the measured
 * duration_ms on succeeded generations); this widget only shapes them for Chart.js.
 */
class GenerationTimingWidget extends ChartWidget
{
    use ResolvesReportWindow;

    // === CONSTANTS ===
    protected int|string|array $columnSpan = 'full';

    // Indigo (the platform accent) for the average-latency line.
    private const LINE_COLOR = 'rgba(99, 102, 241, 1)';
    private const FILL_COLOR = 'rgba(99, 102, 241, 0.12)';

    public function getHeading(): ?string
    {
        return __('platform.costs.timing.title');
    }

    public function getDescription(): ?string
    {
        return __('platform.costs.timing.sub');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * The average try-on render time (ms) per day. An empty dataset (no timed try-ons in the window)
     * renders an empty chart, never an error.
     *
     * @return array<string,mixed>
     */
    protected function getData(): array
    {
        $rows = app(CostsMetricsBuilder::class)->generationTimings($this->reportWindow());

        return [
            'datasets' => [
                [
                    'label' => __('platform.costs.timing.avg_ms'),
                    'data' => array_map(static fn (array $r): int => $r['avgMs'], $rows),
                    'borderColor' => self::LINE_COLOR,
                    'backgroundColor' => self::FILL_COLOR,
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => array_map(static fn (array $r): string => $r['day'], $rows),
        ];
    }
}
