@php
    use App\Domain\Sites\WidgetAppearance;
    $buttonLabel = $data['button_label'] ?? 'Vsio';
@endphp

<x-filament-panels::page>
    @if ($hasSite)
        <form wire:submit="save" class="fi-form grid gap-y-6">
            {{ $this->form }}

            {{-- Button placement — set with the visual picker. --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('appearance.placement.section') }}</x-slot>
                <x-slot name="description">{{ __('appearance.placement.section_sub') }}</x-slot>

                <div class="to-place-summary">
                    <p class="to-place-summary__text">{{ $this->placementSummary() }}</p>
                    <x-filament::button
                        type="button"
                        color="primary"
                        icon="heroicon-o-cursor-arrow-rays"
                        wire:click="openPicker"
                    >
                        {{ __('appearance.visual.pick') }}
                    </x-filament::button>
                </div>
            </x-filament::section>

            <div class="flex justify-end">
                <x-filament::button type="submit">
                    {{ __('appearance.save') }}
                </x-filament::button>
            </div>
        </form>

        {{-- Full-screen visual placement picker. --}}
        @if ($pickerOpen)
            <div
                class="to-place-overlay"
                x-data="{
                    position: @js($pickedPosition),
                    selector: @js($pickedSelector),
                    label: @js($buttonLabel),
                    frameWin() { return $refs.frame ? $refs.frame.contentWindow : null; },
                    post(msg) { const w = this.frameWin(); if (w) { try { w.postMessage(Object.assign({ source: 'trayon-parent' }, msg), '*'); } catch (e) {} } },
                    drawGhost() { this.post({ type: 'setGhost', selector: this.selector, position: this.position, label: this.label }); },
                    setPosition(p) { this.position = p; if (this.selector) { $wire.verifyPick(this.selector, p); this.drawGhost(); } },
                    onMessage(e) {
                        if (!this.$refs.frame || e.source !== this.$refs.frame.contentWindow) return;
                        const d = e.data;
                        if (!d || d.source !== 'trayon-picker') return;
                        if (d.type === 'ready') { this.post({ type: 'label', label: this.label }); if (this.selector) this.drawGhost(); }
                        else if (d.type === 'pick') { this.selector = d.selector; this.position = d.position || 'after'; $wire.verifyPick(d.selector, this.position); }
                    },
                    init() { this._h = (e) => this.onMessage(e); window.addEventListener('message', this._h); },
                    destroy() { window.removeEventListener('message', this._h); },
                }"
            >
                <div class="to-place-dialog">
                    <header class="to-place-head">
                        <div>
                            <p class="to-place-eyebrow">{{ __('appearance.visual.eyebrow') }}</p>
                            <h2 class="to-place-title">{{ __('appearance.visual.modal_title') }}</h2>
                            @if ($previewFinalUrl)
                                <p class="to-place-subtitle">
                                    {{ $previewSource === 'snapshot' ? __('appearance.visual.from_scan') : __('appearance.visual.previewing') }}
                                    <span dir="ltr">{{ $previewFinalUrl }}</span>
                                </p>
                            @endif
                        </div>
                        <x-filament::button type="button" color="gray" icon="heroicon-o-x-mark" wire:click="closePicker">
                            {{ __('appearance.visual.cancel') }}
                        </x-filament::button>
                    </header>

                    <div class="to-place-urlbar">
                        <input
                            type="url"
                            class="to-place-url"
                            wire:model="previewUrl"
                            placeholder="{{ __('appearance.visual.url_placeholder') }}"
                        />
                        <x-filament::button type="button" color="gray" wire:click="loadPreview" wire:target="loadPreview" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="loadPreview">{{ __('appearance.visual.load') }}</span>
                            <span wire:loading wire:target="loadPreview">{{ __('appearance.visual.loading') }}</span>
                        </x-filament::button>
                    </div>

                    @if ($previewError)
                        <p class="to-place-error">{{ $previewError }}</p>
                    @endif

                    <div class="to-place-body">
                        <div class="to-place-stage">
                            @if ($previewToken)
                                <div wire:key="preview-wrap-{{ $previewToken }}" class="to-place-frame-wrap">
                                    <iframe
                                        wire:ignore
                                        x-ref="frame"
                                        class="to-place-frame"
                                        sandbox="allow-scripts"
                                        referrerpolicy="no-referrer"
                                        title="{{ __('appearance.visual.preview') }}"
                                        srcdoc="{{ $this->previewSrcdoc() }}"
                                    ></iframe>
                                </div>
                            @else
                                <div class="to-place-empty">
                                    <p class="to-place-empty__hint">{{ __('appearance.visual.load_hint') }}</p>
                                </div>
                            @endif
                        </div>

                        <aside class="to-place-panel">
                            <p class="to-place-eyebrow">{{ __('appearance.visual.hint') }}</p>

                            @if ($pickVerdict)
                                <div class="to-place-verdict to-place-verdict--{{ ($pickVerdict['ok'] ?? false) ? 'ok' : 'warn' }}">
                                    {{ __('appearance.visual.verdict.'.($pickVerdict['reason'] ?? 'none'), ['count' => $pickVerdict['count'] ?? 0]) }}
                                </div>
                            @endif

                            <div class="to-place-group">
                                <p class="to-place-label">{{ __('appearance.visual.position_label') }}</p>
                                <div class="to-place-positions">
                                    @foreach (WidgetAppearance::POSITIONS as $pos)
                                        <button
                                            type="button"
                                            class="to-btn to-btn--secondary to-place-pos"
                                            x-bind:class="position === @js($pos) ? 'is-active' : ''"
                                            x-on:click="setPosition(@js($pos))"
                                        >
                                            {{ __('appearance.visual.position.'.$pos) }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="to-place-group">
                                <p class="to-place-label">{{ __('appearance.visual.corner_label') }}</p>
                                <div class="to-place-corners">
                                    <button type="button" class="to-btn to-btn--ghost" wire:click="useFloatingCorner('{{ WidgetAppearance::PLACEMENT_FIXED_BR }}')">
                                        {{ __('appearance.visual.corner.br') }}
                                    </button>
                                    <button type="button" class="to-btn to-btn--ghost" wire:click="useFloatingCorner('{{ WidgetAppearance::PLACEMENT_FIXED_BL }}')">
                                        {{ __('appearance.visual.corner.bl') }}
                                    </button>
                                </div>
                            </div>

                            <div class="to-place-actions">
                                <x-filament::button type="button" color="gray" wire:click="closePicker">
                                    {{ __('appearance.visual.cancel') }}
                                </x-filament::button>
                                <x-filament::button type="button" wire:click="applyPick" x-bind:disabled="!selector">
                                    {{ __('appearance.visual.confirm') }}
                                </x-filament::button>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        @endif
    @else
        <x-filament::section>
            <div class="fi-section-empty grid gap-y-2 text-center">
                <p class="font-medium">{{ __('appearance.empty') }}</p>
                <p class="text-sm">{{ __('appearance.empty_sub') }}</p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
