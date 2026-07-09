{{--
    Storyboard Builder — run the pipeline, watch step progress, and generate + edit each frame.
    Buttons drive Livewire methods on the page (StoryboardBuilder). The frame grid + progress poll
    every 5s (paused while inline-editing a prompt) so async generations appear live. Zero inline
    CSS — storyboard.css (.to-sb*, logical properties mirror in HE).

    i18n: platform.storyboard.*
--}}
<x-filament-panels::page>
    @php($steps = $this->getSteps())
    @php($frames = $this->getFrames())
    @php($bible = $this->getVisualBible())

    <div class="to-sb" @if (! $editingFrameId) wire:poll.5s @endif>
        {{-- Pipeline progress --}}
        @if (count($steps) > 0)
            <div class="to-sb-progress">
                @foreach ($steps as $step)
                    <span class="to-sb-step to-sb-step--{{ $step['status'] }}">
                        {{ $step['label'] }}
                        @if ($step['duration']) · {{ $step['duration'] }} @endif
                    </span>
                @endforeach
            </div>
            @foreach ($steps as $step)
                @if ($step['status'] === 'failed' && $step['error'])
                    <p class="to-sb-error">{{ $step['label'] }}: {{ $step['error'] }}</p>
                @endif
            @endforeach
        @else
            <x-to.empty-state
                variant="first-run"
                title="platform.storyboard.builder_empty"
                sub="platform.storyboard.builder_empty_sub"
            />
        @endif

        {{-- Visual bible summary --}}
        @if ($bible)
            <details class="to-sb-bible">
                <summary>{{ __('platform.storyboard.visual_bible') }}</summary>
                <p>{{ $bible['global_style'] ?? '' }}</p>
                @if (! empty($bible['negative_prompt']))
                    <p class="to-sb-bible__neg">{{ __('platform.storyboard.negative') }}: {{ $bible['negative_prompt'] }}</p>
                @endif
            </details>
        @endif

        {{-- Frames --}}
        @if (count($frames) > 0)
            <div class="to-sb-frames">
                @foreach ($frames as $frame)
                    <div @class(['to-sb-frame', 'to-sb-frame--approved' => $frame['approved'], 'to-sb-frame--locked' => $frame['locked']])>
                        <div class="to-sb-frame__media">
                            @if ($frame['videoUrl'])
                                <video src="{{ $frame['videoUrl'] }}" controls preload="metadata" loop muted></video>
                            @elseif ($frame['imageUrl'])
                                <img src="{{ $frame['imageUrl'] }}" alt="Frame {{ $frame['number'] }}" loading="lazy" />
                            @elseif ($frame['generating'])
                                <span class="to-sb-frame__placeholder">{{ __('platform.storyboard.generating') }}</span>
                            @elseif ($frame['failed'])
                                <span class="to-sb-frame__placeholder to-sb-frame__placeholder--fail">{{ __('platform.storyboard.status.failed') }}</span>
                            @else
                                <span class="to-sb-frame__placeholder">{{ __('platform.storyboard.no_image') }}</span>
                            @endif
                        </div>

                        <div class="to-sb-frame__body">
                            <div class="to-sb-frame__head">
                                <span class="to-sb-frame__num">#{{ $frame['number'] }} · {{ $frame['time'] }}</span>
                                <span class="to-sb-frame__flags">
                                    @if ($frame['approved']) <span class="to-sb-flag to-sb-flag--ok">✓</span> @endif
                                    @if ($frame['locked']) <span class="to-sb-flag">🔒</span> @endif
                                </span>
                            </div>

                            <p class="to-sb-frame__desc">{{ $frame['description'] }}</p>

                            @if ($editingFrameId === $frame['id'])
                                {{-- Inline prompt editor --}}
                                <div class="to-sb-frame__edit">
                                    <textarea class="to-sb-input" wire:model="editPrompt" rows="3"
                                        placeholder="{{ __('platform.storyboard.field.image_prompt') }}"></textarea>
                                    <textarea class="to-sb-input" wire:model="editNegative" rows="2"
                                        placeholder="{{ __('platform.storyboard.field.negative') }}"></textarea>
                                    <div class="to-sb-frame__actions">
                                        <x-filament::button size="sm" wire:click="saveEdit(true)" icon="heroicon-o-sparkles">
                                            {{ __('platform.storyboard.save_regenerate') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="saveEdit(false)">
                                            {{ __('platform.storyboard.save') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="cancelEdit">
                                            {{ __('platform.storyboard.cancel') }}
                                        </x-filament::button>
                                    </div>
                                </div>
                            @else
                                <p class="to-sb-frame__prompt">{{ \Illuminate\Support\Str::limit($frame['prompt'], 160) }}</p>

                                {{-- Version thumbnails --}}
                                @if (count($frame['versions']) > 1)
                                    <div class="to-sb-frame__versions">
                                        @foreach ($frame['versions'] as $v)
                                            @if ($v['url'])
                                                <button type="button"
                                                    @class(['to-sb-ver', 'to-sb-ver--selected' => $v['selected']])
                                                    wire:click="selectVersion({{ $frame['id'] }}, {{ $v['id'] }})"
                                                    title="v{{ $v['number'] }}">
                                                    <img src="{{ $v['url'] }}" alt="v{{ $v['number'] }}" loading="lazy" />
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                @unless ($frame['locked'])
                                    <div class="to-sb-frame__actions">
                                        <x-filament::button size="sm" wire:click="generateFrame({{ $frame['id'] }})" icon="heroicon-o-sparkles">
                                            {{ $frame['imageUrl'] ? __('platform.storyboard.regenerate') : __('platform.storyboard.generate') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="startEdit({{ $frame['id'] }})" icon="heroicon-o-pencil">
                                            {{ __('platform.storyboard.edit') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" :color="$frame['approved'] ? 'success' : 'gray'" wire:click="approveFrame({{ $frame['id'] }})">
                                            {{ __('platform.storyboard.approve') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="toggleLock({{ $frame['id'] }})">
                                            {{ __('platform.storyboard.lock') }}
                                        </x-filament::button>
                                        @if ($frame['imageUrl'] && ! $frame['videoGenerating'])
                                            <x-filament::button size="sm" color="gray" wire:click="generateClip({{ $frame['id'] }})" icon="heroicon-o-video-camera">
                                                {{ $frame['videoUrl'] ? __('platform.storyboard.regenerate_clip') : __('platform.storyboard.generate_clip') }}
                                            </x-filament::button>
                                        @elseif ($frame['videoGenerating'])
                                            <span class="to-sb-frame__clip-note">{{ __('platform.storyboard.clip_generating') }}</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="to-sb-frame__actions">
                                        <x-filament::button size="sm" color="gray" wire:click="toggleLock({{ $frame['id'] }})">
                                            {{ __('platform.storyboard.unlock') }}
                                        </x-filament::button>
                                    </div>
                                @endunless
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
