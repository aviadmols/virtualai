<x-filament-panels::page>
    <form wire:submit="save" class="fi-form grid gap-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">
                {{ __('platform.rules.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
