{{--
    Per-shop OVERVIEW hub (record-bound page). Renders the shared hub body — identical
    to the tenant-bound Overview widget — so there is ONE hub surface. All data + actions
    come from RendersShopHub on this page.
--}}
<x-filament-panels::page>
    {{-- Same wrapper the Overview widget uses, so both hub surfaces share ONE vertical rhythm. --}}
    <div class="to-shop-hub">
        @include('filament.merchant.partials.shop-hub')
    </div>
</x-filament-panels::page>
