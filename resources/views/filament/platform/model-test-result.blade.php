{{-- Per-model connectivity test result + the EXACT provider response ("more detail").
     $result: array{ok: bool, title: string, body: string, raw: string} from AiModelResource::probeModel. --}}
<div class="space-y-4 text-sm">
    <div>
        <x-filament::badge :color="$result['ok'] ? 'success' : 'danger'" size="lg">
            {{ $result['title'] }}
        </x-filament::badge>
    </div>

    @if (filled($result['body']))
        <p class="text-gray-700 dark:text-gray-300">{{ $result['body'] }}</p>
    @endif

    @if (filled($result['raw']))
        <div class="space-y-1">
            <p class="font-medium text-gray-950 dark:text-white">
                {{ __('platform.models.test_provider_response') }}
            </p>
            <pre dir="ltr" class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $result['raw'] }}</pre>
        </div>
    @endif
</div>
