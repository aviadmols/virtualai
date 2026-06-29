{{--
    M9 / A13 — privacy & retention settings form. Binds 1:1 to SiteSettingsService
    (validate-then-persist). Fields: retention window (select), free try-ons before
    signup (number, empty = never), show-in-gallery toggle, blur-shopper-photos toggle.
    A typed InvalidSiteSettingsException surfaces as a field error (rendered under the
    field), never a 500. The page provides typed options + state; this view only renders.
    No inline CSS; logical properties so labels/help/errors mirror in HE.

    TOKENS: settings-form.css, buttons.css. i18n: settings.privacy.*, actions.*
--}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot:heading>{{ __('settings.privacy.heading') }}</x-slot:heading>
        @if($this->site())
            <x-slot:description>{{ __('settings.privacy.sub', ['site' => $this->site()->name]) }}</x-slot:description>
        @endif

        @if($hasSite)
            <form wire:submit="save" class="to-form">
                {{-- Retention window --}}
                <div class="to-field">
                    <label class="to-field__label" for="retentionDays">
                        {{ __('settings.privacy.field.retention_days') }}
                    </label>
                    <span class="to-select">
                        <select id="retentionDays" class="to-field__control" wire:model="retentionDays">
                            @foreach($this->retentionOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="to-select__caret" aria-hidden="true"></span>
                    </span>
                    <p class="to-field__help">{{ __('settings.privacy.field.retention_days_help') }}</p>
                    @error('retentionDays')
                        <p class="to-field__error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Free try-ons before signup --}}
                <div class="to-field">
                    <label class="to-field__label" for="freeGenerations">
                        {{ __('settings.privacy.field.free_generations') }}
                    </label>
                    <input
                        id="freeGenerations"
                        type="number"
                        min="0"
                        inputmode="numeric"
                        class="to-field__control to-field__control--number"
                        wire:model="freeGenerations"
                        placeholder="{{ __('settings.privacy.free.never') }}"
                    >
                    <p class="to-field__help">{{ __('settings.privacy.field.free_generations_help') }}</p>
                    @error('freeGenerations')
                        <p class="to-field__error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Show in gallery toggle --}}
                <label class="to-toggle">
                    <input type="checkbox" class="to-toggle__input" wire:model="showInGallery">
                    <span class="to-toggle__track" aria-hidden="true"><span class="to-toggle__thumb"></span></span>
                    <span class="to-toggle__text">
                        <span class="to-toggle__label">{{ __('settings.privacy.field.show_in_gallery') }}</span>
                        <span class="to-toggle__help">{{ __('settings.privacy.field.show_in_gallery_help') }}</span>
                    </span>
                </label>

                {{-- Blur shopper photos toggle --}}
                <label class="to-toggle">
                    <input type="checkbox" class="to-toggle__input" wire:model="blurSourcePhoto">
                    <span class="to-toggle__track" aria-hidden="true"><span class="to-toggle__thumb"></span></span>
                    <span class="to-toggle__text">
                        <span class="to-toggle__label">{{ __('settings.privacy.field.blur_source_photo') }}</span>
                        <span class="to-toggle__help">{{ __('settings.privacy.field.blur_source_photo_help') }}</span>
                    </span>
                </label>

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
</x-filament-panels::page>
