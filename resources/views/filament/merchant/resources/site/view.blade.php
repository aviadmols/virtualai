{{--
    WS1 — per-shop OVERVIEW hub. Ties the current shop's tools together:
      1. a KPI band (confirmed products, try-ons, leads, spendable credit) —
         values are PRE-FORMATTED by the page (SiteHubMetrics); no number is
         computed here;
      2. quick-link cards to the shop's management surfaces (button placement,
         try-on history, registered users, gallery, privacy);
      3. the embed-code block (PUBLIC site_key only, via <x-to.embed-code>) with a
         two-step destructive "regenerate key" control wired to SiteKeyRegenerator;
      4. the shop's scanned products — each row deep-links to the A4 scan-review
         form (M3);
      5. a recent-activity strip (the shop's latest events).
    The page provides typed data ($site, $products, $kpis, $activity); this view
    only renders. No inline CSS, logical properties for RTL.

    TOKENS: kpi-grid.css, kpi-card.css, shop-hub.css, embed-code.css, buttons.css,
      data-table.css, activity-timeline.css. i18n: sites.*, embed.*, settings.*,
      activity.*, states.*
--}}
<x-filament-panels::page>
    {{-- ===================== KPI BAND (WS1) ===================== --}}
    <div class="to-kpi-grid">
        @foreach($kpis as $card)
            <x-to.kpi
                :label="$card['label']"
                :value="$card['value']"
                :tone="$card['tone']"
            />
        @endforeach
    </div>

    {{-- ===================== QUICK-LINK CARDS (WS1) ===================== --}}
    <x-filament::section>
        <x-slot:heading>{{ __('sites.hub.tools.title') }}</x-slot:heading>
        <x-slot:description>{{ __('sites.hub.tools.sub') }}</x-slot:description>

        <div class="to-hub-links">
            <x-to.hub-link
                :href="$this->placementUrl()"
                icon="heroicon-o-cursor-arrow-rays"
                title="sites.hub.tools.placement.title"
                sub="sites.hub.tools.placement.sub"
            />
            <x-to.hub-link
                :href="$this->historyUrl()"
                icon="heroicon-o-sparkles"
                title="sites.hub.tools.history.title"
                sub="sites.hub.tools.history.sub"
            />
            <x-to.hub-link
                :href="$this->usersUrl()"
                icon="heroicon-o-user-group"
                title="sites.hub.tools.users.title"
                sub="sites.hub.tools.users.sub"
            />
            <x-to.hub-link
                :href="$this->galleryUrl()"
                icon="heroicon-o-photo"
                title="sites.hub.tools.gallery.title"
                sub="sites.hub.tools.gallery.sub"
            />
            <x-to.hub-link
                :href="$this->privacyUrl()"
                icon="heroicon-o-shield-check"
                title="sites.hub.tools.privacy.title"
                sub="sites.hub.tools.privacy.sub"
            />
        </div>
    </x-filament::section>

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

    {{-- ===================== RECENT ACTIVITY (WS1 strip) ===================== --}}
    <x-to.site-activity :events="$activity" />
</x-filament-panels::page>
