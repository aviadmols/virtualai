{{--
    P1 — provider spend. One KPI card per AI provider (OpenRouter / BytePlus) with the real cost we
    paid it over the selected window. Values are pre-formatted in ProviderCostsWidget; nothing is
    computed here. Reuses .to-kpi-grid + <x-to.kpi>.

    i18n: platform.costs.providers.* (labels resolved inside <x-to.kpi>).
--}}
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('platform.costs.providers.title')"
        :description="__('platform.costs.providers.sub')"
    >
        @php($providers = $this->getProviders())

        @if($providers['hasData'])
            <div class="to-kpi-grid">
                @foreach($providers['cards'] as $card)
                    <x-to.kpi :label="$card['label']" :value="$card['value']" :tone="$this->tone()" />
                @endforeach
            </div>
        @else
            <p class="to-kpi__sub">{{ __('platform.costs.providers.empty') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
