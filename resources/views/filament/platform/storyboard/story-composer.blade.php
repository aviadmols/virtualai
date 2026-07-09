{{--
    Story-idea composer field — the create/edit form's story prompt, upgraded to the same @-mention
    PILL editor used on the Builder. The reference gallery + @ picker are driven LIVE from the
    "assets" repeater below (tags), with thumbnails resolved from the saved record. Zero inline CSS
    (.to-sb-composer / .to-sb-refs). i18n: platform.storyboard.*
--}}
@php
    // Bind the writable prompt to this field's state; drive the @-picker + gallery LIVE from the
    // sibling reference-image pool (auto-numbered @image1..@imageN as images are dropped in).
    $statePath = $getStatePath();
    $assetsPath = \Illuminate\Support\Str::beforeLast($statePath, '.').'.reference_uploads';
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="to-sb-composer" wire:ignore
        x-data="sbComposer({ statePath: @js($statePath), assetsPath: @js($assetsPath) })"
        @reference-uploads-changed.window="reloadRefs()">
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

        {{-- Live reference gallery — mirrors the tags added in the repeater below. --}}
        <div class="to-sb-refs" x-show="tags.length > 0" x-cloak>
            <div class="to-sb-refs__head">
                {{ __('platform.storyboard.reference_images') }}
                <span class="to-sb-refs__count">(<span x-text="tags.length"></span>)</span>
            </div>
            <div class="to-sb-refs__grid">
                <template x-for="(t, i) in tags" :key="t.tag">
                    <button type="button" class="to-sb-ref" @click="insertTag(t.tag)" :title="'@' + t.tag">
                        <span class="to-sb-ref__num" x-text="i + 1"></span>
                        <img class="to-sb-ref__img" :src="t.url" x-show="t.url" alt="" />
                        <span class="to-sb-ref__tag" x-text="'@' + t.tag"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    @include('filament.platform.storyboard._composer-script')
</x-dynamic-component>
