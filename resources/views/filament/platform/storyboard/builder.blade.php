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

    <div class="to-sb" @if (! $editingFrameId) wire:poll.5s @endif>
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
                @else
                    <p class="to-sb-frame__clip-note">{{ __('platform.storyboard.final_video_generating') }}</p>
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
                                {{-- Inline prompt editor: contenteditable with @-mention PILLS + reference gallery --}}
                                <div class="to-sb-frame__edit" wire:key="edit-{{ $frame['id'] }}">
                                    <div class="to-sb-composer" wire:ignore
                                        x-data="sbComposer({ tags: @js($assetTags), wireProp: 'editPrompt', initial: @js($this->editPrompt) })">
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

    {{--
        sbComposer — a contenteditable prompt editor that renders @tag references as inline PILLS
        (thumbnail + name), with a floating "IMAGES" picker (type @ → arrow/enter select) and a
        reference gallery. It serializes back to plain "@tag" text and syncs to the Livewire prop
        via a deferred $wire.set (sent with the next Save), so no round-trip fights the caret.
    --}}
    <script>
        (function () {
            const TAG_RE = /@([\p{L}\p{N}_]*)$/u;

            const register = () => {
                if (! window.Alpine || window.Alpine.__sbComposerRegistered) return;
                window.Alpine.__sbComposerRegistered = true;

                window.Alpine.data('sbComposer', (config) => ({
                    tags: (config && config.tags) || [],
                    wireProp: (config && config.wireProp) || null,
                    initial: (config && config.initial) || '',
                    show: false,
                    items: [],
                    active: 0,

                    init() {
                        this.renderText(this.initial);
                    },

                    // --- pill element (thumbnail + @label, contenteditable=false, click to remove) ---
                    pill(t) {
                        const span = document.createElement('span');
                        span.className = 'to-sb-pill';
                        span.contentEditable = 'false';
                        span.dataset.tag = t.tag;
                        span.title = '@' + t.tag;
                        if (t.url) {
                            const img = document.createElement('img');
                            img.className = 'to-sb-pill__img';
                            img.src = t.url;
                            img.alt = '';
                            span.appendChild(img);
                        }
                        const label = document.createElement('span');
                        label.className = 'to-sb-pill__label';
                        label.textContent = '@' + t.tag;
                        span.appendChild(label);
                        span.addEventListener('click', (e) => {
                            e.preventDefault();
                            span.remove();
                            this.sync();
                            this.focusEditor();
                        });
                        return span;
                    },

                    // --- render source text -> text nodes + pills (empty stays empty for the placeholder) ---
                    renderText(text) {
                        const el = this.$refs.editor;
                        el.innerHTML = '';
                        if (! text) return;
                        const re = /@([\p{L}\p{N}_]+)/gu;
                        let last = 0, m;
                        while ((m = re.exec(text)) !== null) {
                            if (m.index > last) el.appendChild(document.createTextNode(text.slice(last, m.index)));
                            const known = this.tags.find((t) => t.tag === m[1]);
                            el.appendChild(known ? this.pill(known) : document.createTextNode(m[0]));
                            last = re.lastIndex;
                        }
                        if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
                        if (el.lastChild && el.lastChild.nodeType === Node.ELEMENT_NODE) {
                            el.appendChild(document.createTextNode(' '));
                        }
                    },

                    // --- contenteditable -> plain "@tag" text ---
                    serialize(root) {
                        root = root || this.$refs.editor;
                        let out = '';
                        root.childNodes.forEach((node) => {
                            if (node.nodeType === Node.TEXT_NODE) {
                                out += node.nodeValue;
                            } else if (node.nodeType === Node.ELEMENT_NODE) {
                                if (node.dataset && node.dataset.tag) {
                                    out += '@' + node.dataset.tag;
                                } else if (node.tagName === 'BR') {
                                    out += '\n';
                                } else if (node.tagName === 'DIV' || node.tagName === 'P') {
                                    if (out && ! out.endsWith('\n')) out += '\n';
                                    out += this.serialize(node);
                                } else {
                                    out += this.serialize(node);
                                }
                            }
                        });
                        return out;
                    },

                    sync() {
                        if (this.wireProp && this.$wire) {
                            this.$wire.set(this.wireProp, this.serialize(), false);
                        }
                    },

                    onInput() {
                        this.detect();
                        this.sync();
                    },

                    // detect a trailing "@query" at the caret and open the picker
                    detect() {
                        const sel = window.getSelection();
                        if (! sel || ! sel.rangeCount) { this.show = false; return; }
                        const range = sel.getRangeAt(0);
                        const node = range.startContainer;
                        if (node.nodeType !== Node.TEXT_NODE) { this.show = false; return; }
                        const m = node.nodeValue.slice(0, range.startOffset).match(TAG_RE);
                        if (m) {
                            const q = m[1].toLowerCase();
                            this.items = this.tags.filter((t) => t.tag.toLowerCase().includes(q));
                            this.active = 0;
                            this.show = this.items.length > 0;
                        } else {
                            this.show = false;
                        }
                    },

                    onKeydown(e) {
                        if (! this.show) return;
                        if (e.key === 'ArrowDown') { e.preventDefault(); this.active = (this.active + 1) % this.items.length; }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = (this.active - 1 + this.items.length) % this.items.length; }
                        else if (e.key === 'Enter') { e.preventDefault(); this.pick(this.items[this.active]); }
                        else if (e.key === 'Escape') { this.show = false; }
                    },

                    // replace the trailing "@query" with a pill at the caret
                    pick(t) {
                        if (! t) return;
                        const sel = window.getSelection();
                        if (! sel || ! sel.rangeCount) return;
                        const range = sel.getRangeAt(0);
                        const node = range.startContainer;
                        if (node.nodeType !== Node.TEXT_NODE) { this.appendPill(t); return; }
                        const text = node.nodeValue;
                        const m = text.slice(0, range.startOffset).match(TAG_RE);
                        const start = m ? range.startOffset - m[0].length : range.startOffset;
                        const tail = text.slice(range.startOffset);
                        node.nodeValue = text.slice(0, start);
                        const parent = node.parentNode;
                        const tailNode = document.createTextNode(tail);
                        const space = document.createTextNode(' ');
                        const pill = this.pill(t);
                        parent.insertBefore(tailNode, node.nextSibling);
                        parent.insertBefore(space, node.nextSibling);
                        parent.insertBefore(pill, node.nextSibling);
                        this.caretAfter(space);
                        this.show = false;
                        this.sync();
                    },

                    // append a pill at the end (used by the reference gallery click)
                    insertTag(tag) {
                        const t = this.tags.find((x) => x.tag === tag);
                        if (t) this.appendPill(t);
                    },

                    appendPill(t) {
                        const el = this.$refs.editor;
                        const space = document.createTextNode(' ');
                        el.appendChild(this.pill(t));
                        el.appendChild(space);
                        this.focusEditor();
                        this.caretAfter(space);
                        this.sync();
                    },

                    caretAfter(node) {
                        const sel = window.getSelection();
                        const r = document.createRange();
                        r.setStart(node, node.length);
                        r.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(r);
                    },

                    clearAll() {
                        this.$refs.editor.innerHTML = '';
                        this.show = false;
                        this.sync();
                        this.focusEditor();
                    },

                    focusEditor() {
                        this.$refs.editor.focus();
                    },
                }));
            };

            if (window.Alpine) register();
            else document.addEventListener('alpine:init', register);
        })();
    </script>
</x-filament-panels::page>
