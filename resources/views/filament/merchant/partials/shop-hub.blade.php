{{--
    Shared per-shop OVERVIEW hub body — rendered by BOTH the Overview widget
    (tenant-bound) and the ViewSite page (record-bound). Both provide the data via the
    RendersShopHub trait, so this partial reads everything off $this (kpis(), products(),
    activity(), hubSite(), the regenerate state). No inline CSS; logical props for RTL.

    Sections: (1) KPI band, (2) "Manage this shop" quick-links, (3) install code + PUBLIC
    site_key with a two-step key rotation, (4) products, (5) recent-activity strip.

    TOKENS: kpi-grid.css, kpi-card.css, shop-hub.css, embed-code.css, buttons.css,
      data-table.css, activity-timeline.css. i18n: sites.*, embed.*, activity.*, states.*
--}}
@php
    $hubSite = $this->hubSite();
    $showcase = $this->showcase();
@endphp

{{-- ===================== WELCOME HEADER ===================== --}}
<div class="to-hero-head">
    <p class="to-hero-head__eyebrow">{{ __('sites.hub.welcome.eyebrow') }}</p>
    <h2 class="to-hero-head__greeting">{{ __('sites.hub.welcome.greeting', ['shop' => $this->greetingName()]) }}</h2>
</div>

{{-- ===================== HERO SHOWCASE ===================== --}}
{{-- The shop's latest finished try-ons, big, with a gradient scrim + name. First tile leads
     (2× wide); a trailing gradient tile opens the full gallery. Empty on a fresh shop. --}}
@if ($showcase->isNotEmpty())
    <div class="to-hero">
        <div class="to-hero__strip">
            @foreach ($showcase as $i => $look)
                <a href="{{ $this->galleryUrl() }}"
                   @class(['to-hero__tile', 'to-hero__tile--lead' => $i === 0])
                   wire:key="hero-{{ $look->generationId }}">
                    <img class="to-hero__img" src="{{ $look->resultThumbnailUrl }}" alt="{{ $look->productName }}" loading="lazy" />
                    <span class="to-hero__scrim" aria-hidden="true"></span>
                    <span class="to-hero__meta">
                        <span class="to-hero__name">{{ $look->productName }}</span>
                    </span>
                </a>
            @endforeach
            <a href="{{ $this->galleryUrl() }}" class="to-hero__tile to-hero__tile--cta">
                <span class="to-hero__cta-label">{{ __('sites.hub.hero.view_all') }}</span>
                <x-filament::icon icon="heroicon-m-arrow-right" class="to-hero__cta-icon" />
            </a>
        </div>
    </div>
@endif

{{-- ===================== ONBOARDING CHECKLIST ===================== --}}
@unless ($this->checklistComplete())
    <x-filament::section>
        <x-slot:heading>{{ __('sites.hub.checklist.title') }}</x-slot:heading>
        <x-slot:description>{{ __('sites.hub.checklist.sub') }}</x-slot:description>

        <ol class="to-check">
            @foreach ($this->checklist() as $step)
                <li @class(['to-check__step', 'to-check__step--done' => $step['done']]) wire:key="step-{{ $step['key'] }}">
                    <span class="to-check__dot" aria-hidden="true">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-m-check" />
                        @endif
                    </span>
                    <span class="to-check__body">
                        <span class="to-check__label">{{ __('sites.hub.checklist.' . $step['key'] . '.label') }}</span>
                        <span class="to-check__sub">{{ __('sites.hub.checklist.' . $step['key'] . '.sub') }}</span>
                    </span>
                    @unless ($step['done'])
                        <a href="{{ $step['url'] }}"
                           @if ($step['key'] === 'widget' && $this->isShopifyHub()) target="_top" @endif
                           class="to-check__cta">{{ __('sites.hub.checklist.do') }}</a>
                    @endunless
                </li>
            @endforeach
        </ol>
    </x-filament::section>
@endunless

{{-- ===================== KPI BAND ===================== --}}
<div class="to-kpi-grid">
    @foreach($this->kpis() as $card)
        <x-to.kpi
            :label="$card['label']"
            :value="$card['value']"
            :tone="$card['tone']"
        />
    @endforeach
</div>

{{-- ===================== QUICK-LINK CARDS ===================== --}}
<x-filament::section>
    <x-slot:heading>{{ __('sites.hub.tools.title') }}</x-slot:heading>
    <x-slot:description>{{ __('sites.hub.tools.sub') }}</x-slot:description>

    <div class="to-hub-links">
        @if ($this->isShopifyHub() && $this->themeEditorUrl())
            {{-- Enable the Vsio app-embed block. Opens the Shopify theme editor in the TOP
                 frame (Shopify admin can't nest in our iframe). Replaces the welcome screen's
                 "Open theme editor" step. --}}
            <x-to.hub-link
                :href="$this->themeEditorUrl()"
                target="_top"
                icon="heroicon-o-puzzle-piece"
                title="sites.hub.tools.enable_theme.title"
                sub="sites.hub.tools.enable_theme.sub"
            />
        @endif
        @if ($this->isShopifyHub())
            {{-- Shopify: the button is placed by the theme block, so the card opens the
                 "where the button shows" rule (by tag / type / collection), not the picker. --}}
            <x-to.hub-link
                :href="$this->buttonRulesUrl()"
                icon="heroicon-o-eye"
                title="sites.hub.tools.button_rules.title"
                sub="sites.hub.tools.button_rules.sub"
            />
        @else
            <x-to.hub-link
                :href="$this->placementUrl()"
                icon="heroicon-o-cursor-arrow-rays"
                title="sites.hub.tools.placement.title"
                sub="sites.hub.tools.placement.sub"
            />
        @endif
        <x-to.hub-link
            :href="$this->promptUrl()"
            icon="heroicon-o-pencil-square"
            title="sites.hub.tools.prompt.title"
            sub="sites.hub.tools.prompt.sub"
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

{{-- ===================== EMBED CODE ===================== --}}
<x-to.embed-code
    :siteKey="$hubSite->site_key"
    :scriptSrc="$this->scriptSrc()"
    :installUrl="$this->installUrl()"
    :error="$this->regenerateError"
>
    <x-slot:regenerate>
        @if($this->confirmingRegenerate)
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

{{-- ===================== PRODUCTS → REVIEW ===================== --}}
<x-filament::section>
    <x-slot:heading>{{ __('sites.products.title') }}</x-slot:heading>

    @php
        // Product scan-status is a presentational setup-state (draft/confirmed/failed),
        // NOT one of the status machines — so its plain badge tone is mapped here.
        $statusTone = [
            \App\Models\Product::STATUS_DRAFT => 'warn',
            \App\Models\Product::STATUS_CONFIRMED => 'success',
            \App\Models\Product::STATUS_FAILED => 'danger',
        ];
    @endphp
    @forelse($this->products() as $product)
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

{{-- ===================== RECENT ACTIVITY ===================== --}}
<x-to.site-activity :events="$this->activity()" />
