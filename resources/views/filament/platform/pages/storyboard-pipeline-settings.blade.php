{{--
    Storyboard Pipeline Settings — control every step's engine (provider/model/params) + prompts.
    A section per step; Save writes through to ai_operations / prompts / ai_models. i18n: platform.storyboard.pipe.*
--}}
<x-filament-panels::page>
    <form wire:submit="save" class="to-sb">
        <p class="to-sb__intro">{{ __('platform.storyboard.pipeline_intro') }}</p>

        {{ $this->form }}

        <div class="to-pg__actions">
            <x-filament::button type="submit" icon="heroicon-o-check">
                {{ __('platform.storyboard.pipeline_save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
