{{--
    P1 — the try-on generation log. A scannable table: for each try-on in the selected window, the
    time, account, model + provider, status, provider render time (ms) + real cost. Rows are
    pre-formatted in the page (GenerationLogBuilder); no aggregation here. Reuses costs-breakdown.css
    (.to-cbd*); logical properties mirror in HE.

    i18n: platform.timing_log.*
--}}
<x-filament-panels::page>
    @php($rows = $this->getRows())

    @if(count($rows) > 0)
        <div class="to-cbd">
            <div class="to-cbd__scroll">
                <table class="to-cbd-table">
                    <thead>
                        <tr>
                            <th>{{ __('platform.timing_log.time') }}</th>
                            <th>{{ __('platform.timing_log.account') }}</th>
                            <th>{{ __('platform.timing_log.model') }}</th>
                            <th>{{ __('platform.timing_log.provider') }}</th>
                            <th>{{ __('platform.timing_log.status') }}</th>
                            <th class="to-cbd-table__num">{{ __('platform.timing_log.duration') }}</th>
                            <th class="to-cbd-table__num">{{ __('platform.timing_log.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td dir="ltr">{{ $row['time'] }}</td>
                                <td class="to-cbd-table__name">{{ $row['account'] }}</td>
                                <td dir="ltr">{{ $row['model'] }}</td>
                                <td>{{ $row['provider'] }}</td>
                                <td @class(['to-cbd-table__neg' => $row['failed']])>{{ $row['status'] }}</td>
                                <td class="to-cbd-table__num">{{ $row['duration'] }}</td>
                                <td class="to-cbd-table__num">{{ $row['cost'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <x-to.empty-state variant="first-run" title="platform.timing_log.empty" sub="platform.timing_log.empty_sub" />
    @endif
</x-filament-panels::page>
