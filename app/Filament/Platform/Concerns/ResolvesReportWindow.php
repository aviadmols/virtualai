<?php

namespace App\Filament\Platform\Concerns;

use App\Domain\Reporting\MetricWindow;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * ResolvesReportWindow — a costs WIDGET reads the page's filter state ($this->filters, fed reactively
 * by HasFiltersForm) and turns it into a MetricWindow (MetricWindow::fromFilters). InteractsWithPageFilters
 * supplies the reactive $filters prop. A costs PAGE (which already has $filters via HasFilters) calls
 * MetricWindow::fromFilters directly instead of using this trait.
 */
trait ResolvesReportWindow
{
    use InteractsWithPageFilters;

    /** The reporting window selected on the costs page (default: last 30 days). */
    protected function reportWindow(): MetricWindow
    {
        return MetricWindow::fromFilters($this->filters ?? []);
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
