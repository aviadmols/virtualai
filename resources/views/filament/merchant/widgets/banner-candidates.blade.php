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
            <div class="to-candidate-empty">
                <span class="to-candidate-empty__icon">
                    <x-filament::icon icon="heroicon-o-photo" />
                </span>
                <p class="to-candidate-empty__title">{{ __('banners.candidates.none_title') }}</p>
                <p class="to-candidate-empty__sub">{{ __('banners.candidates.none') }}</p>
            </div>
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
                        $isReady = $asset->status === BannerAsset::STATUS_SUCCEEDED && $thumb;
                        $isSelected = $this->isSelected($asset);
                    @endphp

                    <div
                        class="to-candidate {{ $isSelected ? 'to-candidate--selected' : '' }}"
                        wire:key="banner-candidate-{{ $asset->id }}"
                    >
                        <div class="to-candidate__frame">
                            @if ($isReady)
                                <img class="to-candidate__img" src="{{ $thumb }}" alt="" loading="lazy">

                                @if ($isSelected)
                                    {{-- The chosen artwork — flagged right on the image. --}}
                                    <span class="to-candidate__flag">
                                        <x-filament::icon icon="heroicon-m-check-circle" />
                                        {{ __('banners.candidates.in_use') }}
                                    </span>
                                @else
                                    {{-- The whole image is the select target; the label surfaces on hover/focus. --}}
                                    <button
                                        type="button"
                                        class="to-candidate__pick"
                                        wire:click="useAsset({{ $asset->id }})"
                                        wire:loading.attr="disabled"
                                    >
                                        <span class="to-candidate__pick-label">
                                            <x-filament::icon icon="heroicon-m-check" />
                                            {{ __('banners.candidates.select') }}
                                        </span>
                                    </button>
                                @endif
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

                            @if ($isReady && ! $isSelected)
                                <x-filament::button
                                    size="xs"
                                    icon="heroicon-m-check"
                                    wire:click="useAsset({{ $asset->id }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('banners.candidates.select') }}
                                </x-filament::button>
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
