{{--
    The visual style picker inside the Generate modal (Image Studio + Banners). Each approved
    preset is a Before/After card — its uploaded REFERENCE (before) cross-fading with the
    generated SAMPLE (after). The card is a real <label> wrapping a native radio bound to the
    field's state (wire:model.live), so selection flows through Filament exactly like the old
    dropdown did — the estimate below still reacts, and the action still receives style_id.

    Optional slots: $allowNone renders a leading "no preset" card (radio value "" → free style,
    for surfaces where a style is optional); a style's 'operation' sub-label is skipped when null.

    TOKENS: product-studio.css (.to-stylepick*). No inline styles; the cross-fade + selected ring
    are token-backed classes; logical properties so it mirrors in HE.
--}}
@php($statePath = $getStatePath())
@php($allowNone = (bool) ($allowNone ?? false))

<div class="to-stylepick" role="radiogroup">
    @if ($allowNone)
        <label class="to-stylepick__card" wire:key="stylepick-{{ $statePath }}-none">
            <input
                type="radio"
                class="to-stylepick__radio"
                value=""
                wire:model.live="{{ $statePath }}"
            />

            <span class="to-stylepick__frame">
                <span class="to-stylepick__placeholder to-stylepick__placeholder--none">
                    <x-filament::icon icon="heroicon-o-paint-brush" />
                </span>

                <span class="to-stylepick__check">
                    <x-filament::icon icon="heroicon-m-check-circle" />
                </span>
            </span>

            <span class="to-stylepick__meta">
                <span class="to-stylepick__name">{{ $noneLabel ?? '' }}</span>
                @if (! empty($noneHelp))
                    <span class="to-stylepick__op">{{ $noneHelp }}</span>
                @endif
            </span>
        </label>
    @endif

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
                @if (! empty($style['operation']))
                    <span class="to-stylepick__op">{{ $style['operation'] }}</span>
                @endif
            </span>
        </label>
    @endforeach
</div>
