{{--
    P1 — per-account cost vs revenue. A scannable table: for each merchant account over the selected
    window, the real cost we paid the providers vs the selling value it was billed, plus margin,
    realized markup + charges. Rows are pre-formatted in AccountCostsWidget (top spenders first);
    no aggregation here. Uses costs-breakdown.css (.to-cbd*); logical properties mirror in HE.

    i18n: platform.costs.accounts.*
--}}
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('platform.costs.accounts.title')"
        :description="__('platform.costs.accounts.sub')"
    >
        @php($accounts = $this->getAccounts())

        @if($accounts['hasData'])
            <div class="to-cbd">
                <div class="to-cbd__scroll">
                    <table class="to-cbd-table">
                        <thead>
                            <tr>
                                <th>{{ __('platform.costs.accounts.account') }}</th>
                                <th class="to-cbd-table__num">{{ __('platform.costs.accounts.cost') }}</th>
                                <th class="to-cbd-table__num">{{ __('platform.costs.accounts.revenue') }}</th>
                                <th class="to-cbd-table__num">{{ __('platform.costs.accounts.margin') }}</th>
                                <th class="to-cbd-table__num">{{ __('platform.costs.accounts.markup') }}</th>
                                <th class="to-cbd-table__num">{{ __('platform.costs.accounts.charges') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($accounts['rows'] as $row)
                                <tr>
                                    <td class="to-cbd-table__name">{{ $row['name'] }}</td>
                                    <td class="to-cbd-table__num">{{ $row['cost'] }}</td>
                                    <td class="to-cbd-table__num">{{ $row['revenue'] }}</td>
                                    <td @class(['to-cbd-table__num', 'to-cbd-table__neg' => $row['marginNegative']])>{{ $row['margin'] }}</td>
                                    <td class="to-cbd-table__num">{{ $row['markup'] }}</td>
                                    <td class="to-cbd-table__num">{{ $row['charges'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <p class="to-kpi__sub">{{ __('platform.costs.accounts.empty') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
