{{--
    M3 / A4 — scan-review form. Binds 1:1 to the ScanReview read model ($review)
    + the live ConfirmGate ($gate). Per product field: a confidence chip + an
    editable value + (for a blocking row) a "mark reviewed" acknowledgement. Per
    page selector: the detected selector (read-only) + a manual-entry input + a
    "pick on page" trigger + a "test selector" action whose SelectorTestResult
    outcome renders inline. The confirm bar is DISABLED while the gate is closed
    and names the unreviewed rows; confirming calls the server-side gate.

    All status/level logic is the contract (ScanReviewRow / ConfidenceLevel /
    ConfirmGate); this view only renders. No inline CSS, logical properties only.

    TOKENS: scan-review.css, buttons.css. i18n: scan.*
--}}
<x-filament-panels::page>
    @php
        $identifierFor = static fn ($row) => \App\Domain\Scan\Review\ConfirmGate::identifier($row);
    @endphp

    <div class="to-scan">
        {{-- ===================== PRODUCT FIELDS ===================== --}}
        <section class="to-scan__group">
            <div class="to-scan__group-head">
                <span class="to-scan__group-title">{{ __('scan.fields_heading') }}</span>
                <span class="to-scan__group-sub">{{ __('scan.fields_sub') }}</span>
            </div>

            @foreach($review->fieldRows as $row)
                @php
                    $level = $row->level->level;
                    $id = $identifierFor($row);
                    $reviewed = $this->isReviewed($id);
                    $needsReview = $row->blocksConfirm() && ! $reviewed;
                    $rowClasses = ['to-scan-row', 'to-scan-row--' . $level];
                    if ($needsReview) { $rowClasses[] = 'to-scan-row--needs-review'; }
                    if ($reviewed) { $rowClasses[] = 'to-scan-row--reviewed'; }
                @endphp
                <div class="{{ implode(' ', $rowClasses) }}">
                    <div class="to-scan-row__head">
                        <span class="to-scan-row__label">{{ __($row->i18nLabelKey) }}</span>
                        <span class="to-scan-row__flags">
                            @if($row->optional)
                                <span class="to-scan-row__optional">{{ __('scan.optional') }}</span>
                            @endif
                            <x-to.confidence-chip :level="$level" :labelKey="$row->level->i18nKey()" />
                        </span>
                    </div>

                    <div class="to-scan-row__body">
                        @switch($row->key)
                            @case('description')
                                <textarea
                                    class="to-field"
                                    wire:model="fieldValues.description"
                                    placeholder="{{ __('scan.field.placeholder') }}"
                                    rows="3"
                                ></textarea>
                                @break

                            @case('variants')
                                @if(! empty($row->value))
                                    <div class="to-scan-variants">
                                        @foreach($row->value as $variant)
                                            <span class="to-scan-variant">
                                                {{ collect($variant['options'] ?? [])->implode(' · ') ?: ($variant['sku'] ?? '') }}
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="to-scan-row__hint">{{ __('scan.field.empty') }}</p>
                                @endif
                                @break

                            @case('physical_dimensions')
                                @if(! empty($row->value))
                                    <div class="to-scan-variants">
                                        @foreach((array) $row->value as $dimKey => $dimValue)
                                            <span class="to-scan-variant">{{ $dimKey }}: {{ $dimValue }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="to-scan-row__hint">{{ __('scan.field.empty') }}</p>
                                @endif
                                @break

                            @case('price')
                                <input
                                    type="text"
                                    class="to-field"
                                    value="{{ $row->value }}"
                                    placeholder="{{ __('scan.field.placeholder') }}"
                                    readonly
                                >
                                @break

                            @default
                                <input
                                    type="text"
                                    class="to-field"
                                    wire:model="fieldValues.{{ $row->key }}"
                                    placeholder="{{ __('scan.field.placeholder') }}"
                                >
                        @endswitch

                        @if($row->blocksConfirm())
                            <div>
                                @if($reviewed)
                                    <span class="to-scan-row__reviewed-flag">
                                        <x-filament::icon icon="heroicon-o-check-circle" class="to-scan-row__reviewed-glyph" />
                                        {{ __('scan.reviewed') }}
                                    </span>
                                @else
                                    <button type="button" class="to-btn to-btn--secondary" wire:click="markReviewed('{{ $id }}')">
                                        {{ __('scan.mark_reviewed') }}
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>

        {{-- ===================== PAGE SELECTORS ===================== --}}
        <section class="to-scan__group">
            <div class="to-scan__group-head">
                <span class="to-scan__group-title">{{ __('scan.selectors_heading') }}</span>
                <span class="to-scan__group-sub">{{ __('scan.selectors_sub') }}</span>
            </div>

            @foreach($review->selectorRows as $row)
                @php
                    $level = $row->level->level;
                    $id = $identifierFor($row);
                    $reviewed = $this->isReviewed($id);
                    $needsReview = $row->blocksConfirm() && ! $reviewed;
                    $rowClasses = ['to-scan-row', 'to-scan-row--' . $level];
                    if ($needsReview) { $rowClasses[] = 'to-scan-row--needs-review'; }
                    if ($reviewed) { $rowClasses[] = 'to-scan-row--reviewed'; }
                    $detected = is_string($row->value) && $row->value !== '' ? $row->value : null;
                    $test = $this->testResults[$row->key] ?? null;
                    $testing = $this->testingRole === $row->key;
                @endphp
                <div class="{{ implode(' ', $rowClasses) }}">
                    <div class="to-scan-row__head">
                        <span class="to-scan-row__label">{{ __($row->i18nLabelKey) }}</span>
                        <span class="to-scan-row__flags">
                            <x-to.confidence-chip :level="$level" :labelKey="$row->level->i18nKey()" />
                        </span>
                    </div>

                    <div class="to-scan-row__body">
                        {{-- detected selector (read-only display) --}}
                        <div class="to-selector__detected">
                            <span class="to-selector__detected-label">{{ __('scan.selector.detected') }}</span>
                            <code class="to-selector__detected-value {{ $detected ? '' : 'to-selector__detected-value--empty' }}">
                                {{ $detected ?? __('scan.field.empty') }}
                            </code>
                        </div>

                        {{-- manual selector entry --}}
                        <div class="to-selector__manual">
                            <span class="to-selector__manual-label">{{ __('scan.selector.manual') }}</span>
                            <input
                                type="text"
                                class="to-field to-field--mono"
                                wire:model="selectors.{{ $row->key }}"
                                placeholder="{{ __('scan.selector.manual_placeholder') }}"
                                dir="ltr"
                            >
                        </div>

                        {{-- actions: pick-on-page + test --}}
                        <div class="to-selector__actions">
                            <button
                                type="button"
                                class="to-btn to-btn--ghost"
                                x-data
                                x-tooltip="@js(__('scan.selector.pick_hint'))"
                            >
                                <x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="to-btn__icon-glyph" />
                                {{ __('scan.selector.pick') }}
                            </button>
                            <button
                                type="button"
                                class="to-btn to-btn--secondary"
                                wire:click="testSelector('{{ $row->key }}')"
                                wire:loading.attr="disabled"
                                wire:target="testSelector"
                            >
                                {{ __('scan.selector.test') }}
                            </button>
                        </div>

                        {{-- test outcome / testing spinner --}}
                        @if($testing)
                            <span class="to-selector__test to-selector__test--testing">
                                <span class="to-selector__spinner" aria-hidden="true"></span>
                                {{ __('scan.selector.test') }}
                            </span>
                        @elseif($test)
                            <span class="to-selector__test to-selector__test--{{ $test['outcome'] }}">
                                @switch($test['outcome'])
                                    @case('matched')
                                        <x-filament::icon icon="heroicon-o-check-circle" class="to-selector__test-glyph" />
                                        @break
                                    @case('multiple')
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="to-selector__test-glyph" />
                                        @break
                                    @default
                                        <x-filament::icon icon="heroicon-o-x-circle" class="to-selector__test-glyph" />
                                @endswitch
                                {{ __($test['i18n_key']) }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>

        {{-- ===================== CONFIRM BAR (gate-driven) ===================== --}}
        <div class="to-scan-confirm">
            <div>
                @if($gate->canConfirm)
                    <span class="to-scan-confirm__reason to-scan-confirm__reason--ok">
                        <x-filament::icon icon="heroicon-o-check-circle" class="to-scan-confirm__reason-glyph" />
                        {{ $review->status === \App\Models\Product::STATUS_CONFIRMED ? __('scan.confirmed') : __('scan.ready') }}
                    </span>
                @else
                    <span class="to-scan-confirm__reason">
                        <x-filament::icon icon="heroicon-o-exclamation-circle" class="to-scan-confirm__reason-glyph" />
                        {{ __('scan.blocked.reason') }}
                    </span>
                    @php
                        // Map each blocking identifier ("field:price") back to its row's
                        // real label key — the contract owns the field→label mapping
                        // (e.g. name → scan.field.title), so never reconstruct it here.
                        $labelByIdentifier = [];
                        foreach ($review->rows() as $r) {
                            $labelByIdentifier[$identifierFor($r)] = $r->i18nLabelKey;
                        }
                    @endphp
                    <div class="to-scan-confirm__blocking">
                        @foreach($gate->blockingKeys as $blockingKey)
                            <span class="to-scan-confirm__blocking-chip">
                                {{ __($labelByIdentifier[$blockingKey] ?? $blockingKey) }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <button
                type="button"
                class="to-btn to-btn--primary"
                wire:click="confirm"
                wire:loading.attr="disabled"
                wire:target="confirm"
                @disabled(! $gate->canConfirm)
            >
                {{ __('scan.action.confirm') }}
            </button>
        </div>
    </div>
</x-filament-panels::page>
