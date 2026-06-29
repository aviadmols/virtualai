{{--
    A9 — Empty / loading / error state block.
    The friendly nothing-here surface. Variants: first-run (action CTA),
    filtered-no-results (clear-filters), error (retry). Also used as the table /
    card empty render. All copy via __() from the catalog.

    Props:
      variant   first-run|no-results|error|loading
      title     (optional) i18n key — sensible default per variant
      sub        (optional) i18n key for the sub copy
      icon      (optional) slot for a heroicon illustration

    Slots:
      default   the CTA (e.g. <x-to.cta> for first-run / retry)
      icon      illustration glyph

    TOKENS: empty-state.css. i18n: states.*, plus any passed keys.
--}}
@props([
    'variant' => 'first-run',
    'title' => null,
    'sub' => null,
])
@php
    $defaults = [
        'first-run'  => ['title' => 'states.empty',       'sub' => null],
        'no-results' => ['title' => 'states.no_results',  'sub' => null],
        'error'      => ['title' => 'states.load_failed', 'sub' => null],
        'loading'    => ['title' => 'states.loading',     'sub' => null],
    ];
    $resolvedTitle = $title ?? ($defaults[$variant]['title'] ?? 'states.empty');
    $resolvedSub = $sub ?? ($defaults[$variant]['sub'] ?? null);
@endphp
<div {{ $attributes->class(['to-empty', 'to-empty--' . $variant]) }}>
    @if(isset($icon))
        <div class="to-empty__icon" aria-hidden="true">{{ $icon }}</div>
    @endif

    @if($variant === 'loading')
        <span class="to-skeleton" aria-hidden="true"></span>
    @endif

    <p class="to-empty__title">{{ __($resolvedTitle) }}</p>

    @if($resolvedSub)
        <p class="to-empty__sub">{{ __($resolvedSub) }}</p>
    @endif

    @if($slot->isNotEmpty())
        <div class="to-empty__cta">{{ $slot }}</div>
    @endif
</div>
