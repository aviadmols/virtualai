{{--
    M4 / A5 — site hub page. Renders the embed-code block (PUBLIC site_key only,
    via <x-to.embed-code>) with a two-step destructive "regenerate key" control
    wired to the page (SiteKeyRegenerator), and the site's scanned products — each
    row deep-links to the A4 scan-review form (M3). The page provides typed data
    ($site, $products); this view only renders. No inline CSS, logical properties.

    TOKENS: embed-code.css, buttons.css, data-table.css. i18n: embed.*, sites.*
--}}
<x-filament-panels::page>
    {{-- ===================== EMBED CODE (A5) ===================== --}}
    <x-to.embed-code
        :siteKey="$site->site_key"
        :scriptSrc="$this->scriptSrc()"
        :installUrl="$this->installUrl()"
        :error="$regenerateError"
    >
        <x-slot:regenerate>
            @if($confirmingRegenerate)
                <span class="to-embed__regen-warning">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="to-embed__regen-glyph" />
                    {{ __('embed.regenerate_warning') }}
                </span>
                <button
                    type="button"
                    class="to-btn to-btn--destructive is-confirming"
                    wire:click="regenerate"
                    wire:loading.attr="disabled"
                    wire:target="regenerate"
                >
                    <span wire:loading.remove wire:target="regenerate">{{ __('embed.regenerate_confirm') }}</span>
                    <span wire:loading wire:target="regenerate">{{ __('embed.regenerating') }}</span>
                </button>
                <button type="button" class="to-btn to-btn--ghost" wire:click="cancelRegenerate">
                    {{ __('embed.regenerate_cancel') }}
                </button>
            @else
                <button type="button" class="to-btn to-btn--destructive" wire:click="askRegenerate">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="to-btn__icon-glyph" />
                    {{ __('embed.regenerate') }}
                </button>
            @endif
        </x-slot:regenerate>
    </x-to.embed-code>

    {{-- ===================== PRODUCTS → REVIEW (M3 reach) ===================== --}}
    <x-filament::section>
        <x-slot:heading>{{ __('sites.products.title') }}</x-slot:heading>

        @php
            // Product scan-status is a presentational setup-state (draft/confirmed/
            // failed), NOT one of the §5 status machines — so its plain badge tone is
            // mapped here (same precedent as SiteResource's derived setup_state).
            $statusTone = [
                \App\Models\Product::STATUS_DRAFT => 'warn',
                \App\Models\Product::STATUS_CONFIRMED => 'success',
                \App\Models\Product::STATUS_FAILED => 'danger',
            ];
        @endphp
        @forelse($products as $product)
            <div class="to-scan-row">
                <div class="to-scan-row__head">
                    <span class="to-scan-row__label">{{ $product->name ?: __('sites.products.singular') }}</span>
                    <span class="to-scan-row__flags">
                        <span class="to-badge to-badge--{{ $statusTone[$product->status] ?? 'neutral' }}">
                            <span class="to-badge__dot" aria-hidden="true"></span>
                            {{ __('sites.products.status.' . $product->status) }}
                        </span>
                        <a href="{{ $this->reviewUrl($product) }}" class="to-btn to-btn--secondary">
                            {{ __('sites.action.review') }}
                        </a>
                    </span>
                </div>
            </div>
        @empty
            <x-to.empty-state
                variant="first-run"
                title="sites.products.empty"
                sub="sites.products.empty_sub"
            />
        @endforelse
    </x-filament::section>

    {{-- ===================== SITE SETTINGS (M8 / M9 reach) ===================== --}}
    <x-filament::section>
        <x-slot:heading>{{ __('sites.settings.title') }}</x-slot:heading>

        <div class="to-site-links">
            <a href="{{ $this->galleryUrl() }}" class="to-btn to-btn--secondary">
                <x-filament::icon icon="heroicon-o-photo" class="to-btn__icon-glyph" />
                {{ __('settings.gallery.nav') }}
            </a>
            <a href="{{ $this->privacyUrl() }}" class="to-btn to-btn--secondary">
                <x-filament::icon icon="heroicon-o-shield-check" class="to-btn__icon-glyph" />
                {{ __('settings.privacy.nav') }}
            </a>
        </div>
    </x-filament::section>
</x-filament-panels::page>
