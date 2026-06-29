{{--
    P1 — costs-vs-revenue summary panel. A designed surface (not a chart lib) that
    lays revenue billed against the real OpenRouter cost, with the realized markup
    vs the configured target. Every figure is pre-formatted in CostsVsRevenueWidget
    from the typed CostsMetrics DTO — no number is computed here.

    Bar widths are bucketed to the nearest 5% and applied as modifier classes
    (.to-cvr__fill--p{0..100}) so the gauge carries NO inline style and NO value
    literal — purely token-backed CSS.

    TOKENS: costs-summary.css (.to-cvr*). i18n: platform.costs.summary.*, states.*
--}}
@php
    $s = $this->getSummary();
    // Snap a percent to the nearest 5% bucket the CSS declares.
    $bucket = static fn (int $p): int => (int) (round(min(100, max(0, $p)) / 5) * 5);
@endphp
<x-filament-widgets::widget>
    @if(! $s['hasData'])
        <x-to.empty-state
            variant="first-run"
            title="platform.costs.empty"
            sub="platform.costs.empty_sub"
        />
    @else
        <section class="to-cvr">
            <header class="to-cvr__head">
                <div>
                    <h3 class="to-cvr__title">{{ __('platform.costs.summary.title') }}</h3>
                    <p class="to-cvr__sub">{{ __('platform.costs.summary.sub') }}</p>
                </div>
                <span class="to-cvr__window">{{ __('platform.costs.window', ['days' => $s['window']]) }}</span>
            </header>

            <div class="to-cvr__gauges">
                {{-- Revenue --}}
                <div class="to-cvr__row">
                    <span class="to-cvr__label">{{ __('platform.costs.summary.revenue') }}</span>
                    <div class="to-cvr__track">
                        <span class="to-cvr__fill to-cvr__fill--revenue to-cvr__fill--p{{ $bucket($s['barRevenue']) }}"></span>
                    </div>
                    <span class="to-cvr__amount">{{ $s['revenue'] }}</span>
                </div>

                {{-- Cost --}}
                <div class="to-cvr__row">
                    <span class="to-cvr__label">{{ __('platform.costs.summary.cost') }}</span>
                    <div class="to-cvr__track">
                        <span class="to-cvr__fill to-cvr__fill--cost to-cvr__fill--p{{ $bucket($s['barCost']) }}"></span>
                    </div>
                    <span class="to-cvr__amount">{{ $s['cost'] }}</span>
                </div>

                {{-- Margin --}}
                <div class="to-cvr__row">
                    <span class="to-cvr__label">{{ __('platform.costs.summary.margin') }}</span>
                    <div class="to-cvr__track">
                        <span class="to-cvr__fill {{ $s['marginNegative'] ? 'to-cvr__fill--neg' : 'to-cvr__fill--margin' }} to-cvr__fill--p{{ $bucket($s['barMargin']) }}"></span>
                    </div>
                    <span class="to-cvr__amount {{ $s['marginNegative'] ? 'to-cvr__amount--neg' : '' }}">{{ $s['margin'] }}</span>
                </div>
            </div>

            <footer class="to-cvr__foot">
                <div class="to-cvr__stat">
                    <span class="to-cvr__stat-label">{{ __('platform.costs.summary.margin_ratio', ['value' => $s['marginRatio']]) }}</span>
                </div>
                <div class="to-cvr__markup">
                    <span class="to-cvr__markup-target">{{ __('platform.costs.summary.target', ['value' => $s['markupTarget']]) }}</span>
                    <span class="to-cvr__markup-realized {{ $s['onTarget'] ? 'to-cvr__markup-realized--ok' : 'to-cvr__markup-realized--under' }}">
                        {{ __('platform.costs.summary.realized', ['value' => $s['markupRealized']]) }}
                    </span>
                    <span class="to-badge to-badge--{{ $s['onTarget'] ? 'success' : 'warn' }}">
                        <span class="to-badge__dot" aria-hidden="true"></span>
                        {{ __($s['onTarget'] ? 'platform.costs.summary.on_target' : 'platform.costs.summary.below_target') }}
                    </span>
                </div>
            </footer>
        </section>
    @endif
</x-filament-widgets::widget>
