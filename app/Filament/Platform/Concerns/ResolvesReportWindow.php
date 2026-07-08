<?php

namespace App\Filament\Platform\Concerns;

use App\Domain\Reporting\MetricWindow;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * ResolvesReportWindow — turn the Super-Admin costs page's filter state ($this->filters, fed
 * reactively by the page's HasFiltersForm) into a MetricWindow. A `period` of 7/30/90 → lastDays(N);
 * `custom` → between(from, until). Falls back to the default 30-day window when nothing is set or a
 * custom range is incomplete, so a widget always has a valid window.
 */
trait ResolvesReportWindow
{
    use InteractsWithPageFilters;

    /** The reporting window selected on the costs page (default: last 30 days). */
    protected function reportWindow(): MetricWindow
    {
        $filters = $this->filters ?? [];
        $period = (string) ($filters['period'] ?? (string) MetricWindow::DEFAULT_DAYS);

        if ($period === MetricWindow::LABEL_CUSTOM) {
            $from = $filters['from'] ?? null;
            $until = $filters['until'] ?? null;

            if (filled($from) && filled($until)) {
                return MetricWindow::between(
                    CarbonImmutable::parse((string) $from),
                    CarbonImmutable::parse((string) $until),
                );
            }

            return MetricWindow::lastDays();
        }

        $days = (int) $period;

        return $days > 0 ? MetricWindow::lastDays($days) : MetricWindow::lastDays();
    }

    /** Whole-day length of the current window (for the header pill); 0 for an unbounded window. */
    protected function reportWindowDays(): int
    {
        $window = $this->reportWindow();

        if ($window->from === null || $window->until === null) {
            return 0;
        }

        return (int) round($window->from->diffInDays($window->until));
    }
}
