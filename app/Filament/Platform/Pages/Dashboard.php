<?php

namespace App\Filament\Platform\Pages;

use App\Filament\Platform\Widgets\CostsVsRevenueWidget;
use App\Filament\Platform\Widgets\PlatformKpiWidget;
use App\Filament\Platform\Widgets\QueueHealthWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * P1 — the platform panel home (the Super-Admin "Overview"). Overrides Filament's
 * stock Dashboard to surface, top-to-bottom: the A1 KPI band (platform-wide
 * costs/revenue/margin/markup), then the costs-vs-revenue summary panel.
 *
 * The page renders no data itself — each widget reads the typed CostsMetrics from
 * CostsMetricsBuilder (the one sanctioned cross-account aggregate), so all
 * aggregation lives in the builder, never on this page or in a Blade.
 */
class Dashboard extends BaseDashboard
{
    // === CONSTANTS ===
    // Single column so the KPI band and the summary panel stack full-width; the
    // KPI cards self-arrange inside the band's own responsive grid.
    private const COLUMNS = 1;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    // Attaches to the locked nav order (Overview group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.overview';

    /** The page title — the localised costs view heading. */
    public function getTitle(): string
    {
        return __('platform.costs.title');
    }

    /** The nav label mirrors the title (localised). */
    public static function getNavigationLabel(): string
    {
        return __('platform.costs.title');
    }

    /** The translated nav group (Overview). */
    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    /** Queue/worker health first (is the pipeline running?), then the KPI band + summary. */
    public function getWidgets(): array
    {
        return [
            QueueHealthWidget::class,
            PlatformKpiWidget::class,
            CostsVsRevenueWidget::class,
        ];
    }

    /** A single column so both surfaces span full width. */
    public function getColumns(): int|string|array
    {
        return self::COLUMNS;
    }
}
