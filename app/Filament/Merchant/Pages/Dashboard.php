<?php

namespace App\Filament\Merchant\Pages;

use App\Filament\Merchant\Widgets\CreditBannerWidget;
use App\Filament\Merchant\Widgets\MerchantKpiWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * M1 — the merchant panel home. Overrides Filament's stock Dashboard to surface,
 * top-to-bottom: the A10 credit banner (low/out-of-credit), then the A1 KPI band.
 *
 * The page itself renders no data — each widget resolves the signed-in account
 * (auth()->user()->account) and reads the typed DashboardMetrics snapshot, so all
 * aggregation lives in the builder, never on this page or in a Blade.
 */
class Dashboard extends BaseDashboard
{
    // === CONSTANTS ===
    // Single column so the full-width banner sits cleanly above the KPI band; the
    // KPI cards self-arrange inside the band's own responsive grid.
    private const COLUMNS = 1;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    /** The page title — the localised "Overview". */
    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    /** The nav label mirrors the title (localised). */
    public static function getNavigationLabel(): string
    {
        return __('dashboard.title');
    }

    /** Banner first (A10), then the KPI band (A1). Order is the contract. */
    public function getWidgets(): array
    {
        return [
            CreditBannerWidget::class,
            MerchantKpiWidget::class,
        ];
    }

    /** A single column so the banner spans full width above the KPI grid. */
    public function getColumns(): int|string|array
    {
        return self::COLUMNS;
    }
}
