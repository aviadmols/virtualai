{{--
    WS2 — Try-on history card. One try-on generation as an imagery card (mirrors the A12 gallery
    tile): the signed result thumbnail with the caption on a gradient scrim — the shopper
    (deep-linked to the lead card when there is a lead), the variant and the relative time — and the
    outcome badge pinned to the top corner. A failed / purged / no-image item shows a calm
    placeholder (glyph + note) with the caption on the surface, NEVER a broken image. The item is an
    immutable TryOnHistoryItem DTO from MerchantTryOnHistory — this view only renders it.

    Props: item  TryOnHistoryItem
    TOKENS: history-cards.css. i18n: history.*, status.generation.*
--}}
@props(['item'])
@php
    $hasImage = $item->resultThumbnailUrl && ! $item->purged;
    $shopperName = $item->endUserName ?? __('history.anonymous');
    $variant = ! empty($item->variantOptions) ? implode(' · ', $item->variantOptions) : null;
    $when = $item->createdAt ? \Illuminate\Support\Carbon::parse($item->createdAt)->diffForHumans() : null;
    $note = $item->purged ? __('history.purged') : __('history.no_preview');
    $leadUrl = $item->hasLead()
        ? \App\Filament\Merchant\Resources\EndUserResource\Pages\ViewEndUser::getUrl(['record' => $item->endUserId])
        : null;
@endphp
<figure {{ $attributes->class(['to-history-card']) }}>
    <div class="to-history-card__frame">
        <span class="to-history-card__badge">
            <x-to.badge machine="generation" :status="$item->status" />
        </span>

        @if($hasImage)
            <img
                src="{{ $item->resultThumbnailUrl }}"
                alt="{{ $item->productName ?? $shopperName }}"
                class="to-history-card__img"
                loading="lazy"
            >
        @else
            <span class="to-history-card__placeholder">
                <x-filament::icon icon="heroicon-o-photo" class="to-history-card__placeholder-glyph" />
                <span class="to-history-card__note">{{ $note }}</span>
            </span>
        @endif

        <figcaption @class(['to-history-card__overlay', 'to-history-card__overlay--surface' => ! $hasImage])>
            @if($leadUrl)
                <a href="{{ $leadUrl }}" class="to-history-card__shopper">{{ $shopperName }}</a>
            @else
                <span class="to-history-card__shopper">{{ $shopperName }}</span>
            @endif

            @if($item->productName)
                <span class="to-history-card__product">{{ $item->productName }}</span>
            @endif

            <span class="to-history-card__meta">
                @if($variant)
                    <span class="to-history-card__variant">{{ $variant }}</span>
                @endif
                @if($when)
                    <span class="to-history-card__time">{{ $when }}</span>
                @endif
            </span>
        </figcaption>
    </div>
</figure>
