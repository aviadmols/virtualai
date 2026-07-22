{{--
    WS1 — Shop-hub quick-link card. A big tappable card that deep-links to one of
    the shop's management surfaces (button placement, try-on history, registered
    users, gallery, privacy). A leading heroicon glyph, a title, and a one-line
    sub. The whole card is the link.

    Props:
      href    the deep-link target (built by the hub page; already tenant-scoped)
      icon    heroicon name for the leading glyph
      title   i18n key for the card title
      sub     i18n key for the one-line description

    TOKENS: shop-hub.css. i18n: sites.hub.tools.*
--}}
@props([
    'href',
    'icon',
    'title',
    'sub' => null,
])
{{-- Vertical card: icon tile, title, sentence. The chevron is absolutely placed in
     the end corner (shop-hub.css), so it never squeezes the title's column. --}}
<a href="{{ $href }}" {{ $attributes->class(['to-hub-link']) }}>
    <span class="to-hub-link__icon" aria-hidden="true">
        <x-filament::icon :icon="$icon" class="to-hub-link__glyph" />
    </span>

    <span class="to-hub-link__body">
        <span class="to-hub-link__title">{{ __($title) }}</span>
        @if($sub)
            <span class="to-hub-link__sub">{{ __($sub) }}</span>
        @endif
    </span>

    <x-filament::icon icon="heroicon-o-chevron-right" class="to-hub-link__chevron" />
</a>
