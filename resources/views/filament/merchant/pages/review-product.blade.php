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
                                            {{-- A dimension value may be scalar (chest: 100) or a nested
                                                group (picks / size_map); render scalars, summarise groups. --}}
                                            <span class="to-scan-variant">
                                                {{ $dimKey }}:
                                                {{ is_scalar($dimValue) ? $dimValue : __('scan.field.dimensions') }}
                                            </span>
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

                        {{-- actions: pick-on-page (opens the sandboxed preview picker) + test --}}
                        <div class="to-selector__actions">
                            <button
                                type="button"
                                class="to-btn to-btn--ghost"
                                x-data
                                x-tooltip="@js(__('scan.selector.pick_hint'))"
                                wire:click="openRolePicker('{{ $row->key }}')"
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

        {{-- ===================== PHYSICAL DIMENSIONS (visually picked size / weight) ===================== --}}
        <section class="to-scan__group">
            <div class="to-scan__group-head">
                <span class="to-scan__group-title">{{ __('scan.dimensions_heading') }}</span>
                <span class="to-scan__group-sub">{{ __('scan.dimensions_sub') }}</span>
            </div>

            @foreach(\App\Domain\Scan\ScanConstants::DIMENSION_ROLES as $dimRole)
                @php
                    $pick = $dimensionPicks[$dimRole] ?? [];
                    $pickSelector = $pick['selector'] ?? '';
                    $pickValue = $pick['value'] ?? null;
                @endphp
                <div class="to-scan-row to-scan-row--optional">
                    <div class="to-scan-row__head">
                        <span class="to-scan-row__label">{{ __('scan.dimension.'.$dimRole) }}</span>
                        <span class="to-scan-row__flags">
                            <span class="to-scan-row__optional">{{ __('scan.optional') }}</span>
                        </span>
                    </div>

                    <div class="to-scan-row__body">
                        <div class="to-selector__detected">
                            <span class="to-selector__detected-label">{{ __('scan.dimension.value') }}</span>
                            <code class="to-selector__detected-value {{ $pickValue ? '' : 'to-selector__detected-value--empty' }}" dir="ltr">
                                {{ $pickValue ?? __('scan.dimension.empty') }}
                            </code>
                        </div>

                        <div class="to-selector__detected">
                            <span class="to-selector__detected-label">{{ __('scan.dimension.source') }}</span>
                            <code class="to-selector__detected-value {{ $pickSelector ? '' : 'to-selector__detected-value--empty' }}" dir="ltr">
                                {{ $pickSelector ?: __('scan.field.empty') }}
                            </code>
                        </div>

                        <div class="to-selector__actions">
                            <button
                                type="button"
                                class="to-btn to-btn--ghost"
                                x-data
                                x-tooltip="@js(__('scan.dimension.pick_hint'))"
                                wire:click="openRolePicker('{{ $dimRole }}')"
                            >
                                <x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="to-btn__icon-glyph" />
                                {{ __('scan.dimension.pick') }}
                            </button>
                        </div>
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

            @if($gate->canConfirm)
                <button
                    type="button"
                    class="to-btn to-btn--primary"
                    wire:click="confirm"
                    wire:loading.attr="disabled"
                    wire:target="confirm"
                >
                    {{ __('scan.action.confirm') }}
                </button>
            @else
                {{-- Not everything is reviewed — offer an explicit override (still an explicit
                     confirm, never auto-approve). The warning above still lists what's unreviewed. --}}
                <button
                    type="button"
                    class="to-btn to-btn--secondary"
                    wire:click="confirm(true)"
                    wire:loading.attr="disabled"
                    wire:target="confirm"
                >
                    {{ __('scan.action.confirm_anyway') }}
                </button>
            @endif
        </div>

        {{-- Confirmed → hand off to the visual button-placement picker for this page. --}}
        @if($review->status === \App\Models\Product::STATUS_CONFIRMED)
            <div class="to-scan-confirm">
                <span class="to-scan-confirm__reason to-scan-confirm__reason--ok">
                    <x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="to-scan-confirm__reason-glyph" />
                    {{ __('scan.place.sub') }}
                </span>
                <a href="{{ $this->placeButtonUrl() }}" class="to-btn to-btn--primary" wire:navigate>
                    {{ __('scan.place.cta') }}
                </a>
            </div>
        @endif

        {{-- ===================== VISUAL ROLE PICKER (sandboxed preview) =====================
            REUSES the exact preview rail the placement picker uses: PreviewSnapshotStore →
            PreviewFetcher → PreviewSanitizer → this sandboxed iframe. It runs the SAME picker.js
            inlined by PreviewSanitizer, switched to ROLE mode: a click highlights the picked
            element and posts its selector; the server verifies (resolves-to-one) + reads the value.
            sandbox="allow-scripts" ONLY (no allow-same-origin): the opaque-origin frame can never
            reach the admin session; the picked selector is an untrusted string, verified server-side. --}}
        @if($pickerOpen)
            <div
                class="to-place-overlay"
                x-data="{
                    role: @js($pickerRole),
                    mode: @js($this->pickerMode()),
                    frameWin() { return $refs.frame ? $refs.frame.contentWindow : null; },
                    post(msg) { const w = this.frameWin(); if (w) { try { w.postMessage(Object.assign({ source: 'trayon-parent' }, msg), '*'); } catch (e) {} } },
                    onMessage(e) {
                        if (!this.$refs.frame || e.source !== this.$refs.frame.contentWindow) return;
                        const d = e.data;
                        if (!d || d.source !== 'trayon-picker') return;
                        if (d.type === 'ready') { this.post({ type: 'setMode', mode: this.mode, role: this.role }); }
                        else if (d.type === 'pick' && d.mode === 'role') { $wire.pickRole(d.role || this.role, d.selector); }
                    },
                    init() { this._h = (e) => this.onMessage(e); window.addEventListener('message', this._h); },
                    destroy() { window.removeEventListener('message', this._h); },
                }"
            >
                <div class="to-place-dialog">
                    <header class="to-place-head">
                        <div>
                            <p class="to-place-eyebrow">{{ __('scan.pick.eyebrow') }}</p>
                            <h2 class="to-place-title">
                                {{ __('scan.pick.title', ['role' => __('scan.pick.role.'.$pickerRole)]) }}
                            </h2>
                            @if($previewFinalUrl)
                                <p class="to-place-subtitle">
                                    {{ __('scan.pick.from_scan') }}
                                    <span dir="ltr">{{ $previewFinalUrl }}</span>
                                </p>
                            @endif
                        </div>
                        <x-filament::button type="button" color="gray" icon="heroicon-o-x-mark" wire:click="closePicker">
                            {{ __('scan.pick.cancel') }}
                        </x-filament::button>
                    </header>

                    @if($previewError)
                        <p class="to-place-error">{{ $previewError }}</p>
                    @endif

                    <div class="to-place-body">
                        <div class="to-place-stage">
                            @if($previewToken)
                                <div wire:key="scan-preview-{{ $previewToken }}-{{ $pickerRole }}" class="to-place-frame-wrap">
                                    <iframe
                                        wire:ignore
                                        x-ref="frame"
                                        class="to-place-frame"
                                        sandbox="allow-scripts"
                                        referrerpolicy="no-referrer"
                                        title="{{ __('scan.pick.preview') }}"
                                        srcdoc="{{ $this->previewSrcdoc() }}"
                                    ></iframe>
                                </div>
                            @else
                                <div class="to-place-empty">
                                    <p class="to-place-empty__hint">{{ __('scan.pick.no_snapshot_hint') }}</p>
                                </div>
                            @endif
                        </div>

                        <aside class="to-place-panel">
                            <p class="to-place-eyebrow">{{ __('scan.pick.hint') }}</p>

                            @if($pickVerdict)
                                <div class="to-place-verdict to-place-verdict--{{ ($pickVerdict['ok'] ?? false) ? 'ok' : 'warn' }}">
                                    @if($pickVerdict['ok'] ?? false)
                                        @if($pickerIsDimension && ($pickVerdict['value'] ?? null) !== null)
                                            {{ __('scan.pick.verdict.value', ['value' => $pickVerdict['value']]) }}
                                        @else
                                            {{ __('scan.pick.verdict.unique') }}
                                        @endif
                                    @else
                                        {{ __('scan.pick.verdict.count', ['count' => $pickVerdict['count'] ?? 0]) }}
                                    @endif
                                </div>
                            @endif

                            <div class="to-place-actions">
                                <x-filament::button type="button" color="gray" wire:click="closePicker">
                                    {{ __('scan.pick.cancel') }}
                                </x-filament::button>
                                <x-filament::button type="button" wire:click="closePicker" x-bind:disabled="false">
                                    {{ __('scan.pick.done') }}
                                </x-filament::button>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
