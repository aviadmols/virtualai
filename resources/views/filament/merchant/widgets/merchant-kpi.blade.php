{{--
    M1 / A1 — the merchant KPI band view. Renders the cards the widget pre-built
    (label + pre-formatted value + tone). The value is already aggregated +
    formatted in PHP (DashboardMetrics → MerchantKpiWidget); this view only lays
    out <x-to.kpi> cards in the responsive A1 grid. No number is computed here.

    TOKENS: kpi-grid.css (.to-kpi-grid) + kpi-card.css (<x-to.kpi>).
    i18n: dashboard.kpi.* (resolved inside <x-to.kpi>).
--}}
<x-filament-widgets::widget>
    <div class="to-kpi-grid">
        @foreach($this->getCards() as $card)
            <x-to.kpi
                :label="$card['label']"
                :value="$card['value']"
                :tone="$card['tone']"
            />
        @endforeach
    </div>
</x-filament-widgets::widget>
