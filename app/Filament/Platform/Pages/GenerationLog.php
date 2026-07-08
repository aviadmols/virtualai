<?php

namespace App\Filament\Platform\Pages;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\GenerationLogBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;

/**
 * P1 — the try-on generation log. A Super-Admin table of recent try-ons over the selected window:
 * for EACH call, the time, account, model + provider, status, provider render time (ms) + real cost.
 * The rows come from GenerationLogBuilder (the sanctioned cross-account DB::table read, no PII); this
 * page only formats them. Same date filter as the costs view (MetricWindow::fromFilters).
 */
class GenerationLog extends Page
{
    use HasFiltersForm;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'platform.nav.observability';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.platform.pages.generation-log';

    // The rolling-period options (days) the filter offers, plus a custom range.
    private const PERIOD_OPTIONS = [7, 30, 90];

    private const TITLE = 'platform.timing_log.title';

    private const COST_DECIMALS = 4;

    private const EMPTY_VALUE = '—';

    public function getTitle(): string
    {
        return __(self::TITLE);
    }

    public static function getNavigationLabel(): string
    {
        return __(self::TITLE);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    /** Same period filter as the costs view (7/30/90 days or a custom range). */
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

    /**
     * The render-ready log rows (already formatted).
     *
     * @return array<int,array{time:string,account:string,model:string,provider:string,status:string,duration:string,cost:string,failed:bool}>
     */
    public function getRows(): array
    {
        $rows = app(GenerationLogBuilder::class)->recent(MetricWindow::fromFilters($this->filters ?? []));

        return array_map(fn (array $r): array => [
            'time' => $r['createdAt'] !== null
                ? CarbonImmutable::parse($r['createdAt'])->format('Y-m-d H:i:s')
                : self::EMPTY_VALUE,
            'account' => $r['accountName'],
            'model' => $r['modelUsed'] ?? self::EMPTY_VALUE,
            'provider' => $r['provider'],
            'status' => $r['status'],
            'duration' => $r['durationMs'] !== null ? number_format($r['durationMs']).' ms' : self::EMPTY_VALUE,
            'cost' => $r['costMicroUsd'] !== null
                ? '$'.number_format(CreditMath::microToUsd($r['costMicroUsd']), self::COST_DECIMALS)
                : self::EMPTY_VALUE,
            'failed' => $r['status'] !== Generation::STATUS_SUCCEEDED,
        ], $rows);
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
