<x-filament-panels::page>
    @if ($hasSite)
        <form wire:submit="save" class="fi-form grid gap-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit">
                    {{ __('appearance.save') }}
                </x-filament::button>
            </div>
        </form>
    @else
        <x-filament::section>
            <div class="fi-section-empty grid gap-y-2 text-center">
                <p class="font-medium">{{ __('appearance.empty') }}</p>
                <p class="text-sm">{{ __('appearance.empty_sub') }}</p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
