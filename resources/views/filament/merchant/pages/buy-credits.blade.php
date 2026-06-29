{{--
    M7 / A11 — Buy credits. The amount picker: preset cards (Livewire-selected) +
    the primary "Continue to payment" CTA that hands off to the hosted payment page.
    Money is integer micro-USD at face value; the page provides the presets + the
    selected flag — this view only renders. No inline CSS, logical properties only.
    The chosen-amount text aligns to the end (numeric).

    TOKENS: buy-credits.css (.to-buy-*), buttons.css. i18n: credits.buy.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('credits.buy.heading') }}</x-slot:heading>
        <x-slot:description>{{ __('credits.buy.sub') }}</x-slot:description>

        <div class="to-buy">
            <p class="to-buy__choose">{{ __('credits.buy.choose') }}</p>

            <div class="to-buy__grid" role="radiogroup" aria-label="{{ __('credits.buy.amount') }}">
                @foreach($this->presets() as $preset)
                    <button
                        type="button"
                        class="to-buy__card @if($preset['selected']) is-selected @endif"
                        role="radio"
                        aria-checked="{{ $preset['selected'] ? 'true' : 'false' }}"
                        wire:click="selectAmount({{ $preset['usd'] }})"
                    >
                        <span class="to-buy__amount">{{ $preset['display'] }}</span>
                        @if($preset['selected'])
                            <span class="to-buy__check" aria-hidden="true">
                                <x-filament::icon icon="heroicon-m-check-circle" class="to-buy__check-glyph" />
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>

            <p class="to-buy__note">{{ __('credits.buy.note') }}</p>

            <div class="to-buy__actions">
                <button
                    type="button"
                    class="to-btn to-btn--primary"
                    wire:click="checkout"
                    wire:loading.attr="disabled"
                    wire:target="checkout"
                    @disabled($this->selectedUsd === null)
                >
                    <span wire:loading.remove wire:target="checkout">{{ __('credits.buy.confirm') }}</span>
                    <span wire:loading wire:target="checkout" class="to-buy__redirecting">
                        <span class="to-btn__spinner" aria-hidden="true"></span>
                        {{ __('credits.buy.pending') }}
                    </span>
                </button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
