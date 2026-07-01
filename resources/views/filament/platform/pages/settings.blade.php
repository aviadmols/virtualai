<x-filament-panels::page>
    <form wire:submit="save" class="fi-form grid gap-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">
                {{ __('platform.settings.save') }}
            </x-filament::button>
        </div>
    </form>

    {{-- The full text of the last failed connection test, collapsed behind "Read all". --}}
    @if ($this->lastTestError)
        <details class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-danger-600/20 dark:bg-gray-900 dark:ring-danger-400/20">
            <summary class="cursor-pointer text-sm font-medium text-danger-600 dark:text-danger-400">
                {{ __('platform.settings.read_all') }}
            </summary>
            <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words text-xs text-gray-700 dark:text-gray-300">{{ $this->lastTestError }}</pre>
        </details>
    @endif
</x-filament-panels::page>
