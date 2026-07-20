<?php

namespace App\Filament\Merchant\Pages;

use App\Filament\Merchant\Widgets\CreditBannerWidget;
use App\Filament\Merchant\Widgets\ShopHubWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * M1 — the merchant panel home ("Overview"). Surfaces, top-to-bottom: the A10 credit
 * banner (low/out-of-credit), then the per-shop HUB (the same surface as the ViewSite
 * page): KPI band, "Manage this shop" quick-links, install code, products, activity.
 *
 * The page renders no data itself — each widget resolves the CURRENT SHOP TENANT
 * (Filament::getTenant()), so all aggregation lives in the builder / RendersShopHub,
 * never on this page or in a Blade.
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

    /** Banner first (A10), then the per-shop hub. Order is the contract. */
    public function getWidgets(): array
    {
        return [
            CreditBannerWidget::class,
            ShopHubWidget::class,
        ];
    }

    /** A single column so the banner spans full width above the KPI grid. */
    public function getColumns(): int|string|array
    {
        return self::COLUMNS;
    }
}
