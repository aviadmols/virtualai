{{--
    Banner placement picker (Phase 3) — visually mark the host-page spots the banner is injected
    at. REUSES the club/scan preview rail: the SAME sandboxed iframe (sandbox="allow-scripts" ONLY —
    no allow-same-origin) with picker.js inlined by PreviewSanitizer, in ZONE mode: each click
    accumulates another placement; the server re-verifies (resolves-to-one) before it enters the
    list. The picked selector is untrusted — verified server-side, NEVER executed. No inline CSS;
    reuses the to-place-* / to-zone__* component classes; logical properties mirror in HE.

    TOKENS: place-visually.css, settings-form.css, buttons.css.  i18n: banners.placements.*, actions.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('banners.placements.section') }}</x-slot:heading>
        <x-slot:description>{{ __('banners.placements.section_help') }}</x-slot:description>

        @if($hasBanner)
            <form wire:submit="save" class="to-form">
                <div class="to-zone">
                    <div class="to-zone__head">
                        <span class="to-zone__name">{{ __('banners.placements.picked_label') }}</span>
                        <span class="to-zone__count">
                            {{ trans_choice('banners.placements.count', count($placements), ['count' => count($placements)]) }}
                        </span>
                    </div>

                    @if(count($placements) > 0)
                        <ul class="to-zone__list">
                            @foreach($placements as $index => $placement)
                                <li class="to-zone__chip">
                                    <code class="to-zone__selector" dir="ltr">{{ $placement['selector'] }}</code>
                                    <span class="to-select">
                                        <select class="to-field__control" wire:model="placements.{{ $index }}.position">
                                            @foreach($this->positionOptions() as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <span class="to-select__caret" aria-hidden="true"></span>
                                    </span>
                                    <button
                                        type="button"
                                        class="to-zone__remove"
                                        wire:click="removePlacement({{ $index }})"
                                        aria-label="{{ __('banners.placements.remove') }}"
                                    >
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="to-zone__empty">{{ __('banners.placements.empty') }}</p>
                    @endif

                    <x-filament::button
                        type="button"
                        color="gray"
                        icon="heroicon-o-cursor-arrow-rays"
                        wire:click="openPicker"
                    >
                        {{ __('banners.placements.pick') }}
                    </x-filament::button>
                </div>

                <div class="to-form__actions">
                    <button
                        type="submit"
                        class="to-btn to-btn--primary"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">{{ __('actions.save') }}</span>
                        <span wire:loading wire:target="save" class="to-form__saving">
                            <span class="to-btn__spinner" aria-hidden="true"></span>
                            {{ __('actions.working') }}
                        </span>
                    </button>
                </div>
            </form>
        @else
            <x-to.empty-state variant="first-run" title="banners.placements.no_banner" sub="banners.placements.no_banner_sub" />
        @endif
    </x-filament::section>

    {{-- Full-screen visual picker (multi-pick). The SAME sandboxed-iframe preview the club/scan
         pickers use, in ZONE mode: each click accumulates another placement; the parent re-verifies
         server-side and echoes the confirmed set back with setZones (repainting the numbered marks). --}}
    @if($pickerOpen && $hasBanner)
        <div
            class="to-place-overlay"
            x-data="{
                mode: @js($this->pickerMode()),
                placements: $wire.entangle('placements'),
                selectors() { return (this.placements || []).map((p) => p.selector); },
                frameWin() { return $refs.frame ? $refs.frame.contentWindow : null; },
                post(msg) { const w = this.frameWin(); if (w) { try { w.postMessage(Object.assign({ source: 'trayon-parent' }, msg), '*'); } catch (e) {} } },
                syncZones() { this.post({ type: 'setZones', selectors: this.selectors() }); },
                onMessage(e) {
                    if (!this.$refs.frame || e.source !== this.$refs.frame.contentWindow) return;
                    const d = e.data;
                    if (!d || d.source !== 'trayon-picker') return;
                    if (d.type === 'ready') { this.post({ type: 'setMode', mode: this.mode }); this.syncZones(); }
                    else if (d.type === 'pick' && d.mode === 'zone') { $wire.pickPlacement(d.selector); }
                },
                init() {
                    this._h = (e) => this.onMessage(e);
                    window.addEventListener('message', this._h);
                    this.$watch('placements', () => this.syncZones());
                },
                destroy() { window.removeEventListener('message', this._h); },
            }"
            wire:key="banner-picker-{{ $bannerId }}"
        >
            <div class="to-place-dialog">
                <header class="to-place-head">
                    <div>
                        <p class="to-place-eyebrow">{{ __('banners.plural') }}</p>
                        <h2 class="to-place-title">{{ __('banners.placements.modal_title') }}</h2>
                        @if($previewFinalUrl)
                            <p class="to-place-subtitle">
                                {{ $previewSource === 'snapshot' ? __('banners.placements.from_scan') : __('banners.placements.previewing') }}
                                <span dir="ltr">{{ $previewFinalUrl }}</span>
                            </p>
                        @endif
                    </div>
                    <x-filament::button type="button" color="gray" icon="heroicon-o-x-mark" wire:click="closePicker">
                        {{ __('banners.placements.close') }}
                    </x-filament::button>
                </header>

                <div class="to-place-urlbar">
                    <input
                        type="url"
                        class="to-place-url"
                        wire:model="previewUrl"
                        placeholder="{{ __('banners.placements.url_placeholder') }}"
                    />
                    <x-filament::button type="button" color="gray" wire:click="loadPreview" wire:target="loadPreview" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="loadPreview">{{ __('banners.placements.load') }}</span>
                        <span wire:loading wire:target="loadPreview">{{ __('banners.placements.loading') }}</span>
                    </x-filament::button>
                </div>

                @if($previewError)
                    <p class="to-place-error">{{ $previewError }}</p>
                @endif

                <div class="to-place-body">
                    <div class="to-place-stage">
                        @if($previewToken)
                            <div wire:key="banner-preview-{{ $previewToken }}" class="to-place-frame-wrap">
                                <iframe
                                    wire:ignore
                                    x-ref="frame"
                                    class="to-place-frame"
                                    sandbox="allow-scripts"
                                    referrerpolicy="no-referrer"
                                    title="{{ __('banners.placements.preview') }}"
                                    srcdoc="{{ $this->previewSrcdoc() }}"
                                ></iframe>
                            </div>
                        @else
                            <div class="to-place-empty">
                                <p class="to-place-empty__hint">{{ __('banners.placements.load_hint') }}</p>
                            </div>
                        @endif
                    </div>

                    <aside class="to-place-panel">
                        <p class="to-place-eyebrow">{{ __('banners.placements.hint') }}</p>

                        @if($pickVerdict)
                            <div class="to-place-verdict to-place-verdict--{{ ($pickVerdict['ok'] ?? false) ? 'ok' : 'warn' }}">
                                {{ __('banners.placements.verdict.'.($pickVerdict['reason'] ?? 'none'), ['count' => $pickVerdict['count'] ?? 0, 'max' => \App\Domain\Banners\BannerPlacements::MAX]) }}
                            </div>
                        @endif

                        <div class="to-place-group">
                            <p class="to-place-label">{{ __('banners.placements.picked_label') }}</p>
                            @if(count($placements) > 0)
                                <ul class="to-zone__list">
                                    @foreach($placements as $index => $placement)
                                        <li class="to-zone__chip">
                                            <code class="to-zone__selector" dir="ltr">{{ $placement['selector'] }}</code>
                                            <button
                                                type="button"
                                                class="to-zone__remove"
                                                wire:click="removePlacement({{ $index }})"
                                                aria-label="{{ __('banners.placements.remove') }}"
                                            >
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="to-zone__empty">{{ __('banners.placements.none_yet') }}</p>
                            @endif
                        </div>

                        <div class="to-place-actions">
                            <x-filament::button type="button" wire:click="closePicker">
                                {{ __('banners.placements.done') }}
                            </x-filament::button>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
