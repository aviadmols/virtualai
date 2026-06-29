{{--
    M8 / A12 — the per-site try-on gallery. A responsive tile grid of succeeded
    try-ons (<x-to.gallery-tile>), newest first. States: empty (no site / no try-ons),
    populated. A purged tile renders a placeholder, never a broken image. The page
    provides typed GalleryItem DTOs ($items) + the bound $site; this view only renders.
    No inline CSS; logical properties so the tile flow mirrors in HE.

    TOKENS: gallery.css. i18n: settings.gallery.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('settings.gallery.heading') }}</x-slot:heading>
        @if($site)
            <x-slot:description>{{ __('settings.gallery.sub', ['site' => $site->name]) }}</x-slot:description>
        @endif

        @if($items->isNotEmpty())
            <div class="to-gallery-grid">
                @foreach($items as $item)
                    <x-to.gallery-tile :item="$item" />
                @endforeach
            </div>
        @else
            <x-to.empty-state
                variant="first-run"
                title="settings.gallery.empty"
                sub="settings.gallery.empty_sub"
            />
        @endif
    </x-filament::section>
</x-filament-panels::page>
