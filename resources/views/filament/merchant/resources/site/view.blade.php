{{--
    Per-shop OVERVIEW hub (record-bound page). Renders the shared hub body — identical
    to the tenant-bound Overview widget — so there is ONE hub surface. All data + actions
    come from RendersShopHub on this page.
--}}
<x-filament-panels::page>
    @include('filament.merchant.partials.shop-hub')
</x-filament-panels::page>
