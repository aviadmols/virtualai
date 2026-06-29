{{--
    A5 — Embed-code block. Renders the one-line install snippet for a site, a
    copy button (Alpine navigator.clipboard + a 2s "copied" reset), an install
    hint, and a two-step destructive "regenerate key" control wired to the host
    Livewire page (the regenerate ACTION + its state live on the page; this
    component is the presentation). The PUBLIC site_key is the only secret shown —
    widget_secret is never passed in and never rendered.

    Props:
      siteKey       the public site_key string
      scriptSrc     the widget.js url for the data-site-key snippet
      installUrl    (optional) the install-guide link target
      regenerating  (optional bool) the page's in-flight regenerate state
      error         (optional bool) the page's regenerate-error state

    Slots:
      regenerate    the destructive CTA(s) the host page wires (wire:click)

    TOKENS: embed-code.css. i18n: embed.*
--}}
@props([
    'siteKey',
    'scriptSrc',
    'installUrl' => null,
    'regenerating' => false,
    'error' => false,
])
@php
    $snippet = '<script src="' . e($scriptSrc) . '" data-site-key="' . e($siteKey) . '" async></' . 'script>';
@endphp
<div
    {{ $attributes->class(['to-embed']) }}
    x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($snippet)); this.copied = true; setTimeout(() => this.copied = false, 2000); } }"
>
    <div class="to-embed__head">
        <span class="to-embed__title">{{ __('embed.title') }}</span>
        <span class="to-embed__sub">{{ __('embed.sub') }}</span>
    </div>

    <span class="to-embed__key-label">{{ __('embed.key_label') }}</span>

    <div class="to-embed__code">
        <pre class="to-embed__snippet"><code>{{ $snippet }}</code></pre>
        <div class="to-embed__copy">
            <button type="button" class="to-btn to-btn--secondary" x-on:click="copy()" x-show="!copied">
                <x-filament::icon icon="heroicon-o-clipboard" class="to-btn__icon-glyph" />
                {{ __('embed.copy') }}
            </button>
            <span class="to-embed__copied" x-show="copied" x-cloak>
                <x-filament::icon icon="heroicon-o-check" class="to-embed__copied-glyph" />
                {{ __('embed.copied') }}
            </span>
        </div>
    </div>

    @if($error)
        <span class="to-embed__error">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="to-embed__regen-glyph" />
            {{ __('embed.errors.regenerate') }}
        </span>
    @endif

    <div class="to-embed__footer">
        <div class="to-embed__hint">
            <span>{{ __('embed.install_hint') }}</span>
            @if($installUrl)
                <a href="{{ $installUrl }}" class="to-embed__hint-link" target="_blank" rel="noopener">
                    {{ __('embed.install_hint_link') }}
                </a>
            @endif
        </div>

        <div class="to-embed__regen">
            {{ $regenerate ?? '' }}
        </div>
    </div>
</div>
