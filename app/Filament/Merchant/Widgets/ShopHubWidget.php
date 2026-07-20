<?php

namespace App\Filament\Merchant\Widgets;

use App\Filament\Merchant\Concerns\RendersShopHub;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use RuntimeException;

/**
 * The Overview shop-hub, rendered as a full-width widget on the merchant home so the
 * panel landing shows the SAME hub as the (deep-linkable) ViewSite page: the KPI band,
 * quick-link cards, install code + key rotation, products, and recent activity.
 *
 * The shop is the CURRENT tenant (Filament::getTenant()), never the auth user — the
 * shop-centric account boundary (same rule ResolvesShopAccount + TryOnHistory follow).
 * All hub behaviour lives in RendersShopHub; this widget only supplies hubSite().
 */
class ShopHubWidget extends Widget
{
    use RendersShopHub;

    // === CONSTANTS ===
    protected static string $view = 'filament.merchant.widgets.shop-hub';

    protected int|string|array $columnSpan = 'full';

    protected function hubSite(): Site
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Site) {
            throw new RuntimeException('No shop tenant is bound for the merchant overview.');
        }

        return $tenant;
    }
}
