{{--
    WS2 — per-shop Try-on history. Every try-on generation (the mechanism's
    activations) for the CURRENT shop, newest first. Each row: a signed result
    thumbnail OR a placeholder (purged/failed — never a broken image), the outcome
    badge, the shopper (a link to the lead card when there is a lead), the
    variant/options, and the timestamp. The page provides typed TryOnHistoryItem
    DTOs ($items) + $hasMore + the bound $site; this view only renders.

    Renders each TryOnHistoryItem as an imagery <x-to.history-card> (the gallery scrim pattern).
    No inline CSS; logical properties so the grid mirrors in HE.

    TOKENS: history-cards.css, empty-state.css, badge.css. i18n: history.*, status.generation.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('history.heading') }}</x-slot:heading>
        @if($site)
            <x-slot:description>{{ __('history.sub', ['site' => $site->name]) }}</x-slot:description>
        @endif

        @if($items->isNotEmpty())
            <div class="to-history-grid">
                @foreach($items as $item)
                    <x-to.history-card :item="$item" wire:key="history-{{ $item->generationId }}" />
                @endforeach
            </div>

            @if($hasMore)
                <div class="to-history__more">
                    <x-filament::button
                        color="gray"
                        icon="heroicon-o-arrow-down"
                        wire:click="loadMore"
                    >
                        {{ __('history.load_more') }}
                    </x-filament::button>
                </div>
            @endif
        @else
            <x-to.empty-state
                variant="first-run"
                title="history.empty"
                sub="history.empty_sub"
            />
        @endif
    </x-filament::section>
</x-filament-panels::page>
