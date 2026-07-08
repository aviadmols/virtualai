<?php

namespace App\Filament\Platform\Pages;

use App\Domain\Reporting\MetricWindow;
use App\Filament\Platform\Widgets\AccountCostsWidget;
use App\Filament\Platform\Widgets\CostsVsRevenueWidget;
use App\Filament\Platform\Widgets\PlatformKpiWidget;
use App\Filament\Platform\Widgets\ProviderCostsWidget;
use App\Filament\Platform\Widgets\QueueHealthWidget;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

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
    use HasFiltersForm;

    // === CONSTANTS ===
    // Single column so the KPI band and the summary panel stack full-width; the
    // KPI cards self-arrange inside the band's own responsive grid.
    private const COLUMNS = 1;

    // The rolling-period options (days) the date filter offers, plus a custom range.
    private const PERIOD_OPTIONS = [7, 30, 90];

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
            ProviderCostsWidget::class,
            AccountCostsWidget::class,
        ];
    }

    /** A single column so both surfaces span full width. */
    public function getColumns(): int|string|array
    {
        return self::COLUMNS;
    }

    /**
     * The date filter — a rolling period (7/30/90 days) or a custom range. Its state lives in
     * $this->filters and reaches every widget reactively (ResolvesReportWindow turns it into a
     * MetricWindow), so the whole costs view re-queries when the admin changes the period.
     */
    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->columns(3)
                ->schema([
                    Select::make('period')
                        ->label(__('platform.costs.filter.period'))
                        ->options(self::periodOptions())
                        ->default((string) MetricWindow::DEFAULT_DAYS)
                        ->selectablePlaceholder(false)
                        ->live(),
                    DatePicker::make('from')
                        ->label(__('platform.costs.filter.from'))
                        ->maxDate(now())
                        ->visible(fn (Get $get): bool => $get('period') === MetricWindow::LABEL_CUSTOM),
                    DatePicker::make('until')
                        ->label(__('platform.costs.filter.to'))
                        ->maxDate(now())
                        ->default(now())
                        ->visible(fn (Get $get): bool => $get('period') === MetricWindow::LABEL_CUSTOM),
                ]),
        ]);
    }

    /** period value => localized label (rolling day options + a custom range). */
    private static function periodOptions(): array
    {
        $options = [];

        foreach (self::PERIOD_OPTIONS as $days) {
            $options[(string) $days] = __('platform.costs.filter.last_days', ['days' => $days]);
        }

        $options[MetricWindow::LABEL_CUSTOM] = __('platform.costs.filter.custom');

        return $options;
    }
}
