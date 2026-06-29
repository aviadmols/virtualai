{{--
    M7 / A1 — the credit-balance band. Renders the three cards the widget pre-built
    (label + pre-formatted $ value + tone + optional sub). Every figure is already
    aggregated + formatted in PHP (DashboardMetrics → BalanceWidget); this view only
    lays out <x-to.kpi> cards in the responsive A1 grid. No number is computed here.

    TOKENS: kpi-grid.css (.to-kpi-grid) + kpi-card.css (<x-to.kpi>).
    i18n: credits.kpi.* (resolved inside <x-to.kpi>).
--}}
<x-filament-widgets::widget>
    <div class="to-kpi-grid">
        @foreach($this->getCards() as $card)
            <x-to.kpi
                :label="$card['label']"
                :value="$card['value']"
                :tone="$card['tone']"
                :sub="$card['sub']"
            />
        @endforeach
    </div>
</x-filament-widgets::widget>
