{{--
    A8 — Admin CTA button.
    Variants are modifier classes; never per-call inline overrides. Renders as a
    <button> by default, or <a> when href is set. Loading swaps the label to
    actions.working and shows a spinner.

    Props:
      label      i18n key for the button text
      variant    primary|secondary|destructive|ghost
      href       (optional) renders as a link
      type       button type when not a link (default "button")
      loading    (optional bool)
      disabled   (optional bool)
      icon       (optional) heroicon name slot (caller passes <x-slot:icon>)

    TOKENS: buttons.css. i18n: actions.*, the passed label key.
--}}
@props([
    'label',
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'loading' => false,
    'disabled' => false,
])
@php
    $tag = $href ? 'a' : 'button';
    $classes = ['to-btn', 'to-btn--' . $variant];
    if ($loading) {
        $classes[] = 'to-btn--loading';
    }
    $text = $loading ? __('actions.working') : __($label);
@endphp
<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if($disabled || $loading) @if($tag === 'button') disabled @else aria-disabled="true" @endif @endif
    {{ $attributes->class($classes) }}
>
    @if($loading)
        <span class="to-btn__spinner" aria-hidden="true"></span>
    @elseif(isset($icon))
        {{ $icon }}
    @endif
    {{ $text }}
</{{ $tag }}>
