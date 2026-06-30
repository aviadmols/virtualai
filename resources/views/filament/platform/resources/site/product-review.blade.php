{{--
    P3 — platform read-only scan review (super-admin verify-before-confirm).

    Renders the ScanReview read model (the same contract the merchant A4 form binds to)
    READ-ONLY: every extracted field + page selector with its bucketed confidence chip,
    plus the variants. NOTHING here is editable and NOTHING confirms — confirm is the
    page action (ConfirmScanAction, tenant-bound). G8 template safety: all extracted
    values echo through Blade {{ }} (htmlspecialchars auto-escape), so a merchant/scan
    value containing markup appears verbatim and never executes. Filament-theme classes
    + the shared confidence-chip component only — no inline CSS.
--}}
@php
    /** @var \App\Models\Product $product */
    /** @var \App\Domain\Scan\Review\ScanReview $review */
@endphp

<div class="fi-section grid gap-y-4 text-sm">
    {{-- Product fields --}}
    <div>
        <h4 class="font-medium">{{ __('scan.fields_heading') }}</h4>
        <dl class="mt-2 grid gap-y-2">
            @foreach ($review->fieldRows as $row)
                <div class="flex items-start justify-between gap-x-4">
                    <dt class="text-gray-500 dark:text-gray-400">
                        {{ __($row->i18nLabelKey) }}
                        @if ($row->optional)
                            <span class="text-xs">({{ __('scan.optional') }})</span>
                        @endif
                    </dt>
                    <dd class="flex flex-col items-end gap-y-1 text-right">
                        <span class="font-medium">
                            @if (is_array($row->value))
                                {{ __('platform.sites.products.review.items', ['count' => count($row->value)]) }}
                            @elseif (filled($row->value))
                                {{ \Illuminate\Support\Str::limit((string) $row->value, 140) }}
                            @else
                                <span class="text-gray-400">{{ __('scan.field.empty') }}</span>
                            @endif
                        </span>
                        <x-to.confidence-chip
                            :level="$row->level->level"
                            :labelKey="$row->level->i18nKey()"
                        />
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Page selectors --}}
    <div>
        <h4 class="font-medium">{{ __('scan.selectors_heading') }}</h4>
        <dl class="mt-2 grid gap-y-2">
            @foreach ($review->selectorRows as $row)
                <div class="flex items-start justify-between gap-x-4">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __($row->i18nLabelKey) }}</dt>
                    <dd class="flex flex-col items-end gap-y-1 text-right">
                        <code class="text-xs">
                            @if (filled($row->value))
                                {{ $row->value }}
                            @else
                                <span class="text-gray-400">{{ __('scan.field.empty') }}</span>
                            @endif
                        </code>
                        <x-to.confidence-chip
                            :level="$row->level->level"
                            :labelKey="$row->level->i18nKey()"
                        />
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Variants (read-only) --}}
    @if ($product->variants->isNotEmpty())
        <div>
            <h4 class="font-medium">{{ __('scan.field.variants') }}</h4>
            <ul class="mt-2 grid gap-y-1">
                @foreach ($product->variants as $variant)
                    <li class="flex items-center justify-between gap-x-4">
                        <span>
                            {{ collect($variant->options ?? [])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') ?: __('scan.field.empty') }}
                        </span>
                        @if (filled($variant->sku))
                            <code class="text-xs text-gray-500 dark:text-gray-400">{{ $variant->sku }}</code>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
