@php
    use App\Models\BannerAsset;

    $candidates = $this->candidates;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('banners.candidates.section')"
        :description="__('banners.candidates.section_help')"
        icon="heroicon-o-sparkles"
    >
        @if ($candidates->isEmpty())
            <p class="to-candidate__state-note">{{ __('banners.candidates.none') }}</p>
        @else
            {{-- Poll only while a candidate is still generating; the attribute drops when idle → polling stops. --}}
            <div
                class="to-candidate-grid"
                @if ($interval = $this->pollInterval()) wire:poll.{{ $interval }} @endif
            >
                @foreach ($candidates as $asset)
                    @php
                        $thumb = $this->thumbUrl($asset);
                        $isFailed = in_array($asset->status, [BannerAsset::STATUS_FAILED, BannerAsset::STATUS_CANCELLED], true);
                        $isPending = in_array($asset->status, [BannerAsset::STATUS_PENDING, BannerAsset::STATUS_PROCESSING], true);
                    @endphp

                    <div class="to-candidate" wire:key="banner-candidate-{{ $asset->id }}">
                        <div class="to-candidate__frame">
                            @if ($asset->status === BannerAsset::STATUS_SUCCEEDED && $thumb)
                                <img class="to-candidate__img" src="{{ $thumb }}" alt="" loading="lazy">
                            @elseif ($isPending)
                                <div class="to-candidate__state">
                                    <x-filament::loading-indicator />
                                    <span class="to-candidate__state-note">{{ __('banners.candidates.status.'.$asset->status) }}</span>
                                </div>
                            @else
                                <div class="to-candidate__state to-candidate__state--error">
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                                    <span class="to-candidate__state-note">
                                        {{ $this->failureMessage($asset) ?? __('banners.candidates.status.'.$asset->status) }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <div class="to-candidate__foot">
                            <x-filament::badge
                                :color="match ($asset->status) {
                                    BannerAsset::STATUS_SUCCEEDED => 'success',
                                    BannerAsset::STATUS_FAILED, BannerAsset::STATUS_CANCELLED => 'danger',
                                    default => 'info',
                                }"
                            >
                                {{ __('banners.candidates.status.'.$asset->status) }}
                            </x-filament::badge>

                            @if ($asset->status === BannerAsset::STATUS_SUCCEEDED)
                                @if ($this->isSelected($asset))
                                    <x-filament::badge color="success" icon="heroicon-m-check">
                                        {{ __('banners.candidates.in_use') }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::button
                                        size="xs"
                                        icon="heroicon-m-check"
                                        wire:click="useAsset({{ $asset->id }})"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ __('banners.candidates.select') }}
                                    </x-filament::button>
                                @endif
                            @elseif ($isFailed)
                                <x-filament::button
                                    size="xs"
                                    color="gray"
                                    icon="heroicon-m-arrow-path"
                                    wire:click="retry({{ $asset->id }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('banners.candidates.retry') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
