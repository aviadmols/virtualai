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
    @php($finalVideo = $this->getFinalVideo())

    <div class="to-sb" @if (! $editingFrameId && ! $improvingFrameId && ! $dialogueFrameId) wire:poll.5s @endif>
        @if ($totalCost)
            <p class="to-sb-cost">{{ __('platform.storyboard.total_cost') }}: <strong>{{ $totalCost }}</strong></p>
        @endif

        {{-- Combined video — all frames stitched into one MP4 --}}
        @if ($finalVideo)
            <div class="to-sb-final">
                <div class="to-sb-final__head">{{ __('platform.storyboard.final_video') }}</div>
                @if ($finalVideo['ready'] && $finalVideo['url'])
                    <video class="to-sb-final__player" src="{{ $finalVideo['url'] }}" controls preload="metadata"></video>
                    <a class="to-sb-final__dl" href="{{ $finalVideo['url'] }}" download>{{ __('platform.storyboard.download') }}</a>
                @elseif ($finalVideo['failed'])
                    <p class="to-sb-error">{{ __('platform.storyboard.final_video_failed') }}@if ($finalVideo['error']): {{ $finalVideo['error'] }}@endif</p>
                    @if ($finalVideo['provider'])
                        <p class="to-sb-frame__clip-note">{{ __('platform.storyboard.final_video_status', ['provider' => $finalVideo['provider'], 'model' => $finalVideo['model'] ?? '—', 'status' => $finalVideo['lastStatus'] ?? '—', 'polls' => $finalVideo['polls'] ?? 0]) }}</p>
                    @endif
                @else
                    <p class="to-sb-frame__clip-note">{{ __('platform.storyboard.final_video_generating') }}</p>
                    @if ($finalVideo['submitted'])
                        <p class="to-sb-frame__clip-note">{{ __('platform.storyboard.final_video_status', ['provider' => $finalVideo['provider'] ?? '—', 'model' => $finalVideo['model'] ?? '—', 'status' => $finalVideo['lastStatus'] ?? '—', 'polls' => $finalVideo['polls'] ?? 0]) }}</p>
                    @else
                        <p class="to-sb-frame__clip-note">{{ __('platform.storyboard.final_video_not_submitted') }}</p>
                    @endif
                @endif
            </div>
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
                            @elseif ($frame['failed'])
                                <span class="to-sb-frame__placeholder to-sb-frame__placeholder--fail">{{ __('platform.storyboard.status.failed') }}</span>
                            @elseif (! $frame['generating'])
                                <span class="to-sb-frame__placeholder">{{ __('platform.storyboard.no_image') }}</span>
                            @endif

                            {{-- Generating overlay: shows over an existing image too, so a click is obvious. --}}
                            @if ($frame['generating'])
                                <div class="to-sb-frame__loading">
                                    <span class="to-sb-spinner"></span>
                                    <span>{{ __('platform.storyboard.generating') }}</span>
                                </div>
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
                                {{-- Inline prompt editor: contenteditable with @-mention PILLS + reference gallery --}}
                                <div class="to-sb-frame__edit" wire:key="edit-{{ $frame['id'] }}">
                                    <div class="to-sb-composer" wire:ignore
                                        x-data="sbComposer({ tags: @js($assetTags), statePath: 'editPrompt' })">
                                        <div class="to-sb-composer__box">
                                            <div class="to-sb-composer__editor" contenteditable="true" x-ref="editor"
                                                role="textbox" aria-multiline="true"
                                                data-placeholder="{{ __('platform.storyboard.mention_placeholder') }}"
                                                @input="onInput()" @keydown="onKeydown($event)" @click="detect()"></div>

                                            <div class="to-sb-composer__menu" x-show="show" x-cloak @click.outside="show = false">
                                                <div class="to-sb-composer__menu-head">{{ __('platform.storyboard.mention_images') }}</div>
                                                <template x-for="(t, i) in items" :key="t.tag">
                                                    <button type="button" class="to-sb-composer__opt"
                                                        :class="{ 'to-sb-composer__opt--active': i === active }"
                                                        @mouseenter="active = i" @click="pick(t)">
                                                        <img class="to-sb-composer__thumb" :src="t.url" x-show="t.url" alt="" />
                                                        <span class="to-sb-composer__pill-label">@<span x-text="t.tag"></span></span>
                                                    </button>
                                                </template>
                                                <div class="to-sb-composer__menu-foot">{{ __('platform.storyboard.mention_nav') }}</div>
                                            </div>
                                        </div>

                                        <div class="to-sb-composer__hint">
                                            <button type="button" class="to-sb-composer__clear" @click="clearAll()"
                                                title="{{ __('platform.storyboard.clear') }}">⌫</button>
                                            <span>{{ __('platform.storyboard.mention_hint') }}</span>
                                        </div>

                                        @if (count($assetTags) > 0)
                                            <div class="to-sb-refs">
                                                <div class="to-sb-refs__head">
                                                    {{ __('platform.storyboard.reference_images') }}
                                                    <span class="to-sb-refs__count">({{ count($assetTags) }})</span>
                                                </div>
                                                <div class="to-sb-refs__grid">
                                                    @foreach ($assetTags as $i => $t)
                                                        <button type="button" class="to-sb-ref" @click="insertTag(@js($t['tag']))"
                                                            title="{{ '@'.$t['tag'] }}">
                                                            <span class="to-sb-ref__num">{{ $i + 1 }}</span>
                                                            @if ($t['url'])
                                                                <img class="to-sb-ref__img" src="{{ $t['url'] }}" alt="" loading="lazy" />
                                                            @endif
                                                            <span class="to-sb-ref__tag">{{ '@'.$t['tag'] }}</span>
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
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

                                @if (filled($frame['dialogue']))
                                    <p class="to-sb-frame__dialogue">💬 “{{ $frame['dialogue'] }}”</p>
                                @endif

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

                                {{-- Spoken dialogue: what the character SAYS in this frame — limited to what
                                     fits the frame's seconds; carried into the clip/video generation. --}}
                                @if ($dialogueFrameId === $frame['id'])
                                    <div class="to-sb-frame__edit" x-data="{ len: $wire.dialogueText.length }">
                                        <textarea class="to-sb-input" wire:model="dialogueText" rows="2"
                                            maxlength="{{ $frame['dialogueLimit'] }}"
                                            x-on:input="len = $event.target.value.length"
                                            placeholder="{{ __('platform.storyboard.dialogue_placeholder') }}"></textarea>
                                        <div class="to-sb-composer__hint">
                                            <span>{{ __('platform.storyboard.dialogue_hint', ['chars' => $frame['dialogueLimit'], 'time' => $frame['time']]) }}</span>
                                            <span x-text="len + ' / ' + {{ $frame['dialogueLimit'] }}"></span>
                                        </div>
                                        <div class="to-sb-frame__actions">
                                            <x-filament::button size="sm" wire:click="saveDialogue" icon="heroicon-o-chat-bubble-bottom-center-text">
                                                {{ __('platform.storyboard.save') }}
                                            </x-filament::button>
                                            <x-filament::button size="sm" color="gray" wire:click="cancelDialogue">
                                                {{ __('platform.storyboard.cancel') }}
                                            </x-filament::button>
                                        </div>
                                    </div>
                                @endif

                                {{-- AI improve-prompt: type an instruction, an LLM rewrites this frame's prompt --}}
                                @if ($improvingFrameId === $frame['id'])
                                    <div class="to-sb-frame__edit">
                                        <textarea class="to-sb-input" wire:model="improveInstruction" rows="2"
                                            placeholder="{{ __('platform.storyboard.improve_placeholder') }}"></textarea>
                                        <div class="to-sb-frame__actions">
                                            <x-filament::button size="sm" wire:click="applyImprove(true)" icon="heroicon-o-sparkles">
                                                {{ __('platform.storyboard.improve_apply_regenerate') }}
                                            </x-filament::button>
                                            <x-filament::button size="sm" color="gray" wire:click="applyImprove(false)">
                                                {{ __('platform.storyboard.improve_apply') }}
                                            </x-filament::button>
                                            <x-filament::button size="sm" color="gray" wire:click="cancelImprove">
                                                {{ __('platform.storyboard.cancel') }}
                                            </x-filament::button>
                                        </div>
                                    </div>
                                @endif

                                @unless ($frame['locked'])
                                    <div class="to-sb-frame__actions">
                                        <x-filament::button size="sm" wire:click="generateFrame({{ $frame['id'] }})" icon="heroicon-o-sparkles"
                                            :disabled="$frame['generating']"
                                            wire:target="generateFrame({{ $frame['id'] }})" wire:loading.attr="disabled">
                                            {{ $frame['generating'] ? __('platform.storyboard.generating') : ($frame['imageUrl'] ? __('platform.storyboard.regenerate') : __('platform.storyboard.generate')) }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="startEdit({{ $frame['id'] }})" icon="heroicon-o-pencil">
                                            {{ __('platform.storyboard.edit') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="startImprove({{ $frame['id'] }})" icon="heroicon-o-light-bulb">
                                            {{ __('platform.storyboard.improve') }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="startDialogue({{ $frame['id'] }})" icon="heroicon-o-chat-bubble-bottom-center-text">
                                            {{ __('platform.storyboard.dialogue') }}
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
                                            <span class="to-sb-frame__clip-note">{{ __('platform.storyboard.clip_status', ['provider' => $frame['videoProvider'] ?? '—', 'polls' => $frame['videoPolls'] ?? 0]) }}</span>
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

    @include('filament.platform.storyboard._composer-script')
</x-filament-panels::page>
