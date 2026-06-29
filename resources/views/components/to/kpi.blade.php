{{--
    A1 — KPI / stat card.
    The value is PRE-FORMATTED by laravel-backend (typed DashboardMetrics /
    CostsMetrics) — this card only renders it, never aggregates a number.

    Props:
      label    i18n key for the caption (e.g. dashboard.kpi.balance)
      value    pre-formatted display string (locale-formatted by the backend)
      tone     success|warn|danger|info|neutral (accent edge)
      delta    (optional) pre-formatted delta string, sign included
      deltaUp  (optional bool) direction of the delta (true = up/positive)
      sub      (optional) i18n key for a sublabel
      href     (optional) makes the card a link
      state    default|loading|empty|error

    TOKENS: kpi-card.css. i18n: dashboard.kpi.*, states.no_data, states.load_failed
--}}
@props([
    'label',
    'value' => null,
    'tone' => 'info',
    'delta' => null,
    'deltaUp' => true,
    'sub' => null,
    'href' => null,
    'state' => 'default',
])
@php
    $tag = $href ? 'a' : 'div';
    $classes = ['to-kpi', 'to-kpi--' . $tone];
    if ($state !== 'default') {
        $classes[] = 'to-kpi--' . $state;
    }
    // empty/error glyphs + sublabels come from the shared states.* catalog.
    $displayValue = match ($state) {
        'empty' => '—',
        'error' => '!',
        default => $value,
    };
    $stateSub = match ($state) {
        'empty' => 'states.no_data',
        'error' => 'states.load_failed',
        default => $sub,
    };
@endphp
<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->class($classes) }}
>
    <span class="to-kpi__label">{{ __($label) }}</span>
    <span class="to-kpi__value">{{ $displayValue }}</span>

    @if($delta !== null && $state === 'default')
        <span class="to-kpi__delta {{ $deltaUp ? 'to-kpi__delta--up' : 'to-kpi__delta--down' }}">
            <span class="to-kpi__delta-glyph" aria-hidden="true">{{ $deltaUp ? '▲' : '▼' }}</span>
            {{ $delta }}
        </span>
    @endif

    @if($stateSub)
        <span class="to-kpi__sub">{{ __($stateSub) }}</span>
    @endif
</{{ $tag }}>
