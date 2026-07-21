{{--
    Style Presets table cell: the generated sample cross-fading with the uploaded reference
    (Before/After). $getState() carries the two signed URLs (either may be null). TOKENS:
    style-before-after.css (.to-ba*).
--}}
@php($state = $getState())

<div class="to-ba">
    @if ($state['before'] ?? null)
        <img class="to-ba__img to-ba__img--before" src="{{ $state['before'] }}" alt="" loading="lazy" />
    @endif

    @if ($state['after'] ?? null)
        <img class="to-ba__img to-ba__img--after" src="{{ $state['after'] }}" alt="" loading="lazy" />
    @endif

    @if (! ($state['before'] ?? null) && ! ($state['after'] ?? null))
        <span class="to-ba__placeholder">
            <x-filament::icon icon="heroicon-o-photo" />
        </span>
    @endif
</div>
