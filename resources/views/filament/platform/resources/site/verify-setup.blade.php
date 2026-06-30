{{--
    P3 — "Verify setup" checklist (super-admin, read-only). Answers "is this site
    configured correctly?": each prerequisite for the widget button to appear, shown as
    a ✓ (pass) / ✗ (fail) / note (informational) with a short hint. The checks come from
    SiteResource::verifyChecklist (OpenRouter key, allowed origins, a confirmed product
    via the audited PlatformProductQuery seam). Native Filament-theme classes + heroicons
    only — no inline CSS.
--}}
@php
    /** @var array<int,array{key: string, ok: bool, info: bool, label: string, hint: string}> $checks */
@endphp

<ul class="grid gap-y-3 text-sm">
    @foreach ($checks as $check)
        <li class="flex items-start gap-x-3">
            <span class="mt-0.5 shrink-0">
                @if ($check['info'])
                    <x-filament::icon
                        icon="heroicon-o-information-circle"
                        @class(['h-5 w-5', 'text-gray-400'])
                    />
                @elseif ($check['ok'])
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        @class(['h-5 w-5', 'text-success-500'])
                    />
                @else
                    <x-filament::icon
                        icon="heroicon-o-x-circle"
                        @class(['h-5 w-5', 'text-danger-500'])
                    />
                @endif
            </span>
            <span class="grid gap-y-0.5">
                <span class="font-medium">{{ $check['label'] }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $check['hint'] }}</span>
            </span>
        </li>
    @endforeach
</ul>
