{{--
    Customer-Club settings (Phase 2b-UI / 2c) — enable the club, set the member
    discount %, and VISUALLY pick where the member price is displayed per surface
    (PDP / catalog / cart), one or MORE zones each. Binds 1:1 to SiteSettingsService
    (validate-then-persist via ClubConfig::sanitize). A typed InvalidSiteSettingsException
    (reason invalid_club_config) surfaces as a soft field error, never a 500. The page
    provides typed state; this view only renders.

    The zone picker REUSES the placement/scan preview rail: the SAME sandboxed iframe
    (sandbox="allow-scripts" ONLY — no allow-same-origin) with picker.js inlined by
    PreviewSanitizer, switched to ZONE mode: each click accumulates another price
    element; the server re-verifies (resolves-to-one) before it enters the surface's
    zone list. The picked selector is untrusted — verified server-side, NEVER executed.
    No inline CSS; logical properties so labels/errors/chips mirror in HE.

    TOKENS: settings-form.css, place-visually.css, buttons.css.
    i18n: club.settings.*, club.zones.*, actions.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('club.settings.heading') }}</x-slot:heading>
        @if($this->site())
            <x-slot:description>{{ __('club.settings.sub', ['site' => $this->site()->name]) }}</x-slot:description>
        @endif

        @if($hasSite)
            <form wire:submit="save" class="to-form">
                {{-- Enable the club --}}
                <label class="to-toggle">
                    <input type="checkbox" class="to-toggle__input" wire:model="enabled">
                    <span class="to-toggle__track" aria-hidden="true"><span class="to-toggle__thumb"></span></span>
                    <span class="to-toggle__text">
                        <span class="to-toggle__label">{{ __('club.settings.field.enabled') }}</span>
                        <span class="to-toggle__help">{{ __('club.settings.field.enabled_help') }}</span>
                    </span>
                </label>

                {{-- Member discount percent --}}
                <div class="to-field">
                    <label class="to-field__label" for="discountPercent">
                        {{ __('club.settings.field.discount_percent') }}
                    </label>
                    <input
                        id="discountPercent"
                        type="number"
                        min="0"
                        max="100"
                        inputmode="numeric"
                        class="to-field__control to-field__control--number"
                        wire:model="discountPercent"
                    >
                    <p class="to-field__help">{{ __('club.settings.field.discount_percent_help') }}</p>
                    @error('discountPercent')
                        <p class="to-field__error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Per-surface price zones — visual pickers. --}}
                <div class="to-field">
                    <p class="to-field__label">{{ __('club.zones.section') }}</p>
                    <p class="to-field__help">{{ __('club.zones.section_help') }}</p>

                    <div class="to-zones">
                        @foreach($this->surfaces() as $surface)
                            @php($zones = $this->zonesFor($surface))
                            <div class="to-zone">
                                <div class="to-zone__head">
                                    <span class="to-zone__name">{{ __('club.zones.surface.'.$surface) }}</span>
                                    <span class="to-zone__count">
                                        {{ trans_choice('club.zones.count', count($zones), ['count' => count($zones)]) }}
                                    </span>
                                </div>

                                @if(count($zones) > 0)
                                    <ul class="to-zone__list">
                                        @foreach($zones as $index => $selector)
                                            <li class="to-zone__chip">
                                                <code class="to-zone__selector" dir="ltr">{{ $selector }}</code>
                                                <button
                                                    type="button"
                                                    class="to-zone__remove"
                                                    wire:click="removeZone('{{ $surface }}', {{ $index }})"
                                                    aria-label="{{ __('club.zones.remove') }}"
                                                >
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="to-zone__empty">{{ __('club.zones.empty') }}</p>
                                @endif

                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    icon="heroicon-o-cursor-arrow-rays"
                                    wire:click="openPicker('{{ $surface }}')"
                                >
                                    {{ __('club.zones.pick') }}
                                </x-filament::button>
                            </div>
                        @endforeach
                    </div>
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
            <x-to.empty-state variant="first-run" title="sites.empty" sub="sites.empty_sub" />
        @endif
    </x-filament::section>

    {{-- Full-screen visual zone picker (multi-pick). The SAME sandboxed-iframe preview
         the placement + scan pickers use, in ZONE mode: each click accumulates another
         price element; the parent re-verifies server-side and echoes the confirmed set
         back with setZones (which repaints the numbered highlights). --}}
    @if($pickerOpen && $hasSite)
        <div
            class="to-place-overlay"
            x-data="{
                surface: @js($pickerSurface),
                mode: @js($this->pickerMode()),
                {{-- entangle the surface's confirmed zone array: Alpine re-derives it on
                     every server round-trip, so a verified pick / removal repaints itself. --}}
                zones: $wire.entangle('priceZones.' + @js($pickerSurface)),
                frameWin() { return $refs.frame ? $refs.frame.contentWindow : null; },
                post(msg) { const w = this.frameWin(); if (w) { try { w.postMessage(Object.assign({ source: 'trayon-parent' }, msg), '*'); } catch (e) {} } },
                syncZones() { this.post({ type: 'setZones', selectors: this.zones || [] }); },
                onMessage(e) {
                    if (!this.$refs.frame || e.source !== this.$refs.frame.contentWindow) return;
                    const d = e.data;
                    if (!d || d.source !== 'trayon-picker') return;
                    if (d.type === 'ready') { this.post({ type: 'setMode', mode: this.mode, role: this.surface }); this.syncZones(); }
                    else if (d.type === 'pick' && d.mode === 'zone') { $wire.pickZone(this.surface, d.selector); }
                },
                init() {
                    this._h = (e) => this.onMessage(e);
                    window.addEventListener('message', this._h);
                    {{-- Repaint whenever the server updates the confirmed zone list. --}}
                    this.$watch('zones', () => this.syncZones());
                },
                destroy() { window.removeEventListener('message', this._h); },
            }"
            wire:key="club-picker-{{ $pickerSurface }}"
        >
            <div class="to-place-dialog">
                <header class="to-place-head">
                    <div>
                        <p class="to-place-eyebrow">{{ __('club.zones.eyebrow') }}</p>
                        <h2 class="to-place-title">
                            {{ __('club.zones.modal_title', ['surface' => __('club.zones.surface.'.$pickerSurface)]) }}
                        </h2>
                        @if($previewFinalUrl)
                            <p class="to-place-subtitle">
                                {{ $previewSource === 'snapshot' ? __('club.zones.from_scan') : __('club.zones.previewing') }}
                                <span dir="ltr">{{ $previewFinalUrl }}</span>
                            </p>
                        @endif
                    </div>
                    <x-filament::button type="button" color="gray" icon="heroicon-o-x-mark" wire:click="closePicker">
                        {{ __('club.zones.close') }}
                    </x-filament::button>
                </header>

                {{-- URL bar: the live-preview fallback (needed for catalog + cart; also
                     available for PDP if the merchant wants a different page). --}}
                <div class="to-place-urlbar">
                    <input
                        type="url"
                        class="to-place-url"
                        wire:model="previewUrl"
                        placeholder="{{ __('club.zones.url_placeholder') }}"
                    />
                    <x-filament::button type="button" color="gray" wire:click="loadPreview" wire:target="loadPreview" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="loadPreview">{{ __('club.zones.load') }}</span>
                        <span wire:loading wire:target="loadPreview">{{ __('club.zones.loading') }}</span>
                    </x-filament::button>
                </div>

                @if($previewError)
                    <p class="to-place-error">{{ $previewError }}</p>
                @endif

                <div class="to-place-body">
                    <div class="to-place-stage">
                        @if($previewToken)
                            <div wire:key="club-preview-{{ $previewToken }}-{{ $pickerSurface }}" class="to-place-frame-wrap">
                                <iframe
                                    wire:ignore
                                    x-ref="frame"
                                    class="to-place-frame"
                                    sandbox="allow-scripts"
                                    referrerpolicy="no-referrer"
                                    title="{{ __('club.zones.preview') }}"
                                    srcdoc="{{ $this->previewSrcdoc() }}"
                                ></iframe>
                            </div>
                        @else
                            <div class="to-place-empty">
                                <p class="to-place-empty__hint">{{ __('club.zones.load_hint') }}</p>
                            </div>
                        @endif
                    </div>

                    <aside class="to-place-panel">
                        <p class="to-place-eyebrow">{{ __('club.zones.hint') }}</p>

                        @if($pickVerdict)
                            <div class="to-place-verdict to-place-verdict--{{ ($pickVerdict['ok'] ?? false) ? 'ok' : 'warn' }}">
                                {{ __('club.zones.verdict.'.($pickVerdict['reason'] ?? 'none'), ['count' => $pickVerdict['count'] ?? 0, 'max' => \App\Domain\Sites\ClubConfig::ZONES_PER_SURFACE_MAX]) }}
                            </div>
                        @endif

                        <div class="to-place-group">
                            <p class="to-place-label">{{ __('club.zones.picked_label') }}</p>
                            @php($current = $this->zonesFor($pickerSurface))
                            @if(count($current) > 0)
                                <ul class="to-zone__list">
                                    @foreach($current as $index => $selector)
                                        <li class="to-zone__chip">
                                            <code class="to-zone__selector" dir="ltr">{{ $selector }}</code>
                                            <button
                                                type="button"
                                                class="to-zone__remove"
                                                wire:click="removeZone('{{ $pickerSurface }}', {{ $index }})"
                                                aria-label="{{ __('club.zones.remove') }}"
                                            >
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="to-zone__empty">{{ __('club.zones.none_yet') }}</p>
                            @endif
                        </div>

                        <div class="to-place-actions">
                            <x-filament::button type="button" wire:click="closePicker">
                                {{ __('club.zones.done') }}
                            </x-filament::button>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
