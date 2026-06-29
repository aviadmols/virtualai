{{--
    A2 — Status badge (pill).
    Resolves tone via App\Support\Ui\StatusBadge (the CONST map) — never inline
    logic. Always carries translated text (a11y); never colour-only.

    Props:
      machine  one of generation|ledger|credit|lead|scan
      status   the bare status value within that machine
      tone     (optional) override — normally resolved from machine+status
      label    (optional) override the i18n key

    TOKENS: --to-badge--{tone} classes (badge.css). i18n: status.* / scan.confidence.*
--}}
@props([
    'machine',
    'status',
    'tone' => null,
    'label' => null,
])
@php
    use App\Support\Ui\StatusBadge;
    $resolvedTone = $tone ?? StatusBadge::tone($machine, $status);
    $resolvedLabel = $label ?? StatusBadge::label($machine, $status);
@endphp
<span {{ $attributes->class(['to-badge', 'to-badge--' . $resolvedTone]) }}>
    <span class="to-badge__dot" aria-hidden="true"></span>
    {{ __($resolvedLabel) }}
</span>
