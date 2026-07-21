{{--
    The visual style picker inside the Generate modal. Each approved Image-Studio preset is a
    Before/After card — its uploaded REFERENCE (before) cross-fading with the generated SAMPLE
    (after). The card is a real <label> wrapping a native radio bound to the field's state
    (wire:model.live), so selection flows through Filament exactly like the old dropdown did — the
    estimate below still reacts, and the action still receives style_id.

    TOKENS: product-studio.css (.to-stylepick*). No inline styles; the cross-fade + selected ring
    are token-backed classes; logical properties so it mirrors in HE.
--}}
@php($statePath = $getStatePath())

<div class="to-stylepick" role="radiogroup">
    @foreach ($styles as $style)
        <label class="to-stylepick__card" wire:key="stylepick-{{ $statePath }}-{{ $style['id'] }}">
            <input
                type="radio"
                class="to-stylepick__radio"
                value="{{ $style['id'] }}"
                wire:model.live="{{ $statePath }}"
            />

            <span class="to-stylepick__frame">
                @if ($style['after'] || $style['before'])
                    @if ($style['before'])
                        <img class="to-stylepick__img to-stylepick__img--before" src="{{ $style['before'] }}" alt="" loading="lazy" />
                    @endif
                    @if ($style['after'])
                        <img class="to-stylepick__img to-stylepick__img--after" src="{{ $style['after'] }}" alt="" loading="lazy" />
                    @endif

                    @if ($style['before'] && $style['after'])
                        <span class="to-stylepick__tag to-stylepick__tag--before">{{ __('product_images.style_card.before') }}</span>
                        <span class="to-stylepick__tag to-stylepick__tag--after">{{ __('product_images.style_card.after') }}</span>
                    @endif
                @else
                    <span class="to-stylepick__placeholder">
                        <x-filament::icon icon="heroicon-o-photo" />
                    </span>
                @endif

                <span class="to-stylepick__check">
                    <x-filament::icon icon="heroicon-m-check-circle" />
                </span>
            </span>

            <span class="to-stylepick__meta">
                <span class="to-stylepick__name">{{ $style['name'] }}</span>
                <span class="to-stylepick__op">{{ $style['operation'] }}</span>
            </span>
        </label>
    @endforeach
</div>
