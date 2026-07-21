{{--
    A7 — the shared initials avatar chip (DRY: used by the leads list identity cell and the lead-card
    header). Warm-gradient initials from the name (first + last word), falling back to the email, then
    a neutral person-glyph tile when fully anonymous. Decorative (aria-hidden); the readable name sits
    beside it. Props: name, email, size ('lg' for the 48px header variant). TOKENS: lead-card.css.
--}}
@props(['name' => null, 'email' => null, 'size' => null])
@php
    $source = trim((string) ($name ?: $email));
    $hasIdentity = $source !== '';
    $parts = $hasIdentity ? array_values(array_filter(preg_split('/\s+/', $source))) : [];
    $initials = '';
    if ($parts) {
        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= mb_strtoupper(mb_substr($parts[array_key_last($parts)], 0, 1));
        }
    }
    $classes = ['to-lead-avatar'];
    if ($size === 'lg') { $classes[] = 'to-lead-avatar--lg'; }
    if (! $hasIdentity) { $classes[] = 'to-lead-avatar--anon'; }
@endphp
<span {{ $attributes->class($classes) }} aria-hidden="true">
    @if($hasIdentity)
        {{ $initials }}
    @else
        <x-filament::icon icon="heroicon-o-user" class="to-lead-avatar__glyph" />
    @endif
</span>
