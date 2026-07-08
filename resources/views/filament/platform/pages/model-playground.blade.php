{{--
    Model Playground — run an image/video model with a prompt + input images and see the result,
    render time + cost. The form submits to run(); the history gallery below polls every 5s so an
    async video (or a just-finished image) appears without a manual refresh. The form sits OUTSIDE
    the polled region so a refresh never resets an in-progress upload. Zero inline CSS — layout is
    playground.css (.to-pg*, logical properties mirror in HE).

    i18n: platform.playground.*
--}}
<x-filament-panels::page>
    <form wire:submit="run" class="to-pg">
        {{ $this->form }}

        <div class="to-pg__actions">
            <x-filament::button type="submit" icon="heroicon-o-play">
                {{ __('platform.playground.run') }}
            </x-filament::button>
        </div>
    </form>

    @php($runs = $this->getRuns())

    <div class="to-pg" wire:poll.5s>
        <h2 class="to-pg__heading">{{ __('platform.playground.history') }}</h2>

        @if (count($runs) === 0)
            <x-to.empty-state
                variant="first-run"
                title="platform.playground.empty"
                sub="platform.playground.empty_sub"
            />
        @else
            <div class="to-pg-runs">
                @foreach ($runs as $run)
                    <div class="to-pg-run">
                        <div class="to-pg-run__media">
                            @if ($run['resultUrl'] && $run['isVideo'])
                                <video src="{{ $run['resultUrl'] }}" controls preload="metadata"></video>
                            @elseif ($run['resultUrl'])
                                <img src="{{ $run['resultUrl'] }}" alt="{{ $run['model'] }}" loading="lazy" />
                            @elseif ($run['running'])
                                <span class="to-pg-run__placeholder">{{ __('platform.playground.generating') }}</span>
                            @else
                                <span class="to-pg-run__placeholder">{{ __('platform.playground.no_result') }}</span>
                            @endif
                        </div>

                        <div class="to-pg-run__body">
                            <span class="to-pg-run__status to-pg-run__status--{{ $run['status'] }}">
                                {{ $run['statusLabel'] }}
                            </span>

                            <span class="to-pg-run__model" dir="ltr">{{ $run['model'] }}</span>
                            <span class="to-pg-run__prompt">{{ $run['prompt'] }}</span>

                            @if ($run['failed'] && $run['error'])
                                <span class="to-pg-run__error">{{ $run['error'] }}</span>
                            @endif

                            <div class="to-pg-run__facts">
                                <span>{{ __('platform.playground.col.provider') }}:
                                    <span class="to-pg-run__fact-value">{{ $run['providerLabel'] }}</span></span>
                                <span>{{ __('platform.playground.col.time') }}:
                                    <span class="to-pg-run__fact-value">{{ $run['time'] ?? '—' }}</span></span>
                                <span>{{ __('platform.playground.col.cost') }}:
                                    <span class="to-pg-run__fact-value">{{ $run['cost'] }}</span></span>
                                @if ($run['tokens'])
                                    <span>{{ __('platform.playground.col.tokens') }}:
                                        <span class="to-pg-run__fact-value">{{ number_format($run['tokens']) }}</span></span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
