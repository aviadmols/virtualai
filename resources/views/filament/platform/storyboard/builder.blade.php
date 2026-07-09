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
    @php($totalCost = $this->getTotalCost())
    @php($assetTags = $this->getAssetTags())

    <div class="to-sb" @if (! $editingFrameId) wire:poll.5s @endif>
        @if ($totalCost)
            <p class="to-sb-cost">{{ __('platform.storyboard.total_cost') }}: <strong>{{ $totalCost }}</strong></p>
        @endif

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

            <details class="to-sb-log" x-data="{ open: false }" :open="open">
                <summary @click.prevent="open = ! open">{{ __('platform.storyboard.process_log') }}</summary>
                @foreach ($steps as $step)
                    <div class="to-sb-log__step">
                        <div class="to-sb-log__head">
                            <span class="to-sb-step to-sb-step--{{ $step['status'] }}">{{ $step['label'] }}</span>
                            <span class="to-sb-log__meta" dir="ltr">
                                @if ($step['model']) {{ $step['model'] }} @endif
                                @if ($step['duration']) · {{ $step['duration'] }} @endif
                                @if ($step['cost']) · {{ $step['cost'] }} @endif
                            </span>
                        </div>
                        @if ($step['error'])
                            <pre class="to-sb-log__pre to-sb-log__pre--error" dir="ltr">{{ $step['error'] }}</pre>
                        @elseif ($step['output'])
                            <pre class="to-sb-log__pre" dir="ltr">{{ $step['output'] }}</pre>
                        @endif
                    </div>
                @endforeach
            </details>
        @else
            <x-to.empty-state
                variant="first-run"
                title="platform.storyboard.builder_empty"
                sub="platform.storyboard.builder_empty_sub"
            />
        @endif

        {{-- Visual bible summary --}}
        @if ($bible)
            <details class="to-sb-bible" x-data="{ open: false }" :open="open">
                <summary @click.prevent="open = ! open">{{ __('platform.storyboard.visual_bible') }}</summary>
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

                            @if ($frame['failed'] && $frame['error'])
                                <p class="to-sb-error">{{ $frame['error'] }}</p>
                            @elseif ($frame['videoError'])
                                <p class="to-sb-error">{{ $frame['videoError'] }}</p>
                            @endif

                            @if ($editingFrameId === $frame['id'])
                                {{-- Inline prompt editor with @-mention of reference images --}}
                                <div class="to-sb-frame__edit">
                                    <div class="to-sb-mention" x-data="sbMention(@js($assetTags))">
                                        <textarea x-ref="ta" class="to-sb-input" wire:model="editPrompt" rows="3"
                                            @input="onInput($event)" @keydown.escape="show = false"
                                            placeholder="{{ __('platform.storyboard.mention_placeholder') }}"></textarea>
                                        <div class="to-sb-mention__menu" x-show="show" x-cloak @click.outside="show = false">
                                            <template x-for="t in items" :key="t.tag">
                                                <button type="button" class="to-sb-mention__item" @click="pick(t.tag)">
                                                    <img class="to-sb-mention__thumb" :src="t.url" x-show="t.url" alt="" />
                                                    <span>@<span x-text="t.tag"></span></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
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

    {{-- @-mention: type @ in a prompt to pick a reference image (inserts its @tag). --}}
    <script>
        (function () {
            const register = () => {
                if (! window.Alpine || window.Alpine.__sbMentionRegistered) return;
                window.Alpine.__sbMentionRegistered = true;
                window.Alpine.data('sbMention', (tags) => ({
                    tags: tags || [],
                    show: false,
                    items: [],
                    onInput(e) {
                        const el = e.target;
                        const before = el.value.slice(0, el.selectionStart);
                        const m = before.match(/@([\p{L}\p{N}_]*)$/u);
                        if (m) {
                            const q = m[1].toLowerCase();
                            this.items = this.tags.filter((t) => t.tag.toLowerCase().includes(q));
                            this.show = this.items.length > 0;
                        } else {
                            this.show = false;
                        }
                    },
                    pick(tag) {
                        const el = this.$refs.ta;
                        const pos = el.selectionStart;
                        const before = el.value.slice(0, pos).replace(/@([\p{L}\p{N}_]*)$/u, '@' + tag + ' ');
                        el.value = before + el.value.slice(pos);
                        el.dispatchEvent(new Event('input'));
                        this.show = false;
                        el.focus();
                    },
                }));
            };
            if (window.Alpine) register();
            else document.addEventListener('alpine:init', register);
        })();
    </script>
</x-filament-panels::page>
