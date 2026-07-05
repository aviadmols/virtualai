{{--
    WS2 — per-shop Try-on history. Every try-on generation (the mechanism's
    activations) for the CURRENT shop, newest first. Each row: a signed result
    thumbnail OR a placeholder (purged/failed — never a broken image), the outcome
    badge, the shopper (a link to the lead card when there is a lead), the
    variant/options, and the timestamp. The page provides typed TryOnHistoryItem
    DTOs ($items) + $hasMore + the bound $site; this view only renders.

    Reuses the A7 lead-card / attempt component classes (lead-card.css) — no new CSS.
    No inline CSS; logical properties so the row flow mirrors in HE.

    TOKENS: lead-card.css, empty-state.css, badge.css. i18n: history.*, status.generation.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('history.heading') }}</x-slot:heading>
        @if($site)
            <x-slot:description>{{ __('history.sub', ['site' => $site->name]) }}</x-slot:description>
        @endif

        @if($items->isNotEmpty())
            <div class="to-lead-card">
                <div class="to-lead-card__history">
                    @foreach($items as $item)
                        <div class="to-attempt">
                            @if($item->resultThumbnailUrl && ! $item->purged)
                                <img
                                    src="{{ $item->resultThumbnailUrl }}"
                                    alt="{{ $item->productName ?? __('history.col.result') }}"
                                    class="to-attempt__thumb"
                                    loading="lazy"
                                >
                            @else
                                <span class="to-attempt__thumb to-attempt__thumb--placeholder">
                                    <x-filament::icon icon="heroicon-o-photo" class="to-attempt__thumb-glyph" />
                                </span>
                            @endif

                            <div class="to-attempt__body">
                                <span class="to-attempt__product">{{ $item->productName ?? __('history.col.no_product') }}</span>

                                @if($item->hasLead())
                                    <a
                                        href="{{ \App\Filament\Merchant\Resources\EndUserResource\Pages\ViewEndUser::getUrl(['record' => $item->endUserId]) }}"
                                        class="to-attempt__shopper-link"
                                    >
                                        {{ $item->endUserName ?? __('history.anonymous') }}
                                    </a>
                                @else
                                    <span class="to-attempt__variant">{{ __('history.anonymous') }}</span>
                                @endif

                                @if(! empty($item->variantOptions))
                                    <span class="to-attempt__variant">{{ implode(' · ', $item->variantOptions) }}</span>
                                @endif

                                @if($item->purged)
                                    <span class="to-attempt__purged">{{ __('history.purged') }}</span>
                                @endif
                            </div>

                            <div class="to-attempt__meta">
                                <x-to.badge machine="generation" :status="$item->status" />
                                @if($item->createdAt)
                                    <span class="to-attempt__when">
                                        {{ \Illuminate\Support\Carbon::parse($item->createdAt)->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
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
