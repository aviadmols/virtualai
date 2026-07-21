{{--
    A12 — Gallery tile. One succeeded try-on: the result thumbnail at --to-r-card,
    a product + variant caption. A purged tile shows a placeholder glyph + the purged
    note, NEVER a broken image. The item is an immutable GalleryItem DTO from
    MerchantGalleryQuery — this view only renders it, it never queries.

    Props:
      item   GalleryItem (generationId, productName, variantOptions,
             resultThumbnailUrl, purged, …)

    TOKENS: gallery.css. i18n: settings.gallery.*
--}}
@props(['item'])
@php
    $caption = $item->productName ?: __('settings.gallery.caption.no_product');
    $variant = ! empty($item->variantOptions) ? implode(' · ', $item->variantOptions) : null;
@endphp
<figure {{ $attributes->class(['to-gallery-tile']) }}>
    <div class="to-gallery-tile__frame">
        @if($item->resultThumbnailUrl && ! $item->purged)
            <img
                src="{{ $item->resultThumbnailUrl }}"
                alt="{{ $caption }}"
                class="to-gallery-tile__img"
                loading="lazy"
            >
            {{-- The caption rides ON the image over a gradient scrim (imagery-forward). --}}
            <figcaption class="to-gallery-tile__overlay">
                <span class="to-gallery-tile__product">{{ $caption }}</span>
                @if($variant)
                    <span class="to-gallery-tile__variant">{{ $variant }}</span>
                @endif
            </figcaption>
        @else
            <span class="to-gallery-tile__placeholder">
                <x-filament::icon icon="heroicon-o-photo" class="to-gallery-tile__placeholder-glyph" />
                <span class="to-gallery-tile__purged">{{ __('settings.gallery.purged') }}</span>
            </span>
        @endif
    </div>
</figure>
