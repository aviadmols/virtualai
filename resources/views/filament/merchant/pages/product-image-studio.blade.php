{{--
    Product Image Studio (Phase 4) — bulk AI product-image generation + merchant review.

    Three sections: the charge notice + balance, the LIVE batch progress (counters written by the
    workers, polled), and the review grid (signed URLs, approve/reject/regenerate per tile).

    The page provides every value ($activeBatch, $counts, $tiles, $spendable) as typed data; this
    view only renders — no queries, no signing, no money math in Blade.

    TOKENS: product-studio.css (.to-studio-*), badge.css, empty-state.css — no new literals,
    no inline styles, logical properties only (mirrors in HE). i18n: product_images.*
--}}
<x-filament-panels::page>

    {{-- The money contract, stated before anything is generated. --}}
    <x-filament::section>
        <x-slot:heading>{{ __('product_images.heading') }}</x-slot:heading>
        <x-slot:description>{{ __('product_images.sub') }}</x-slot:description>

        <dl class="to-studio__facts">
            <div class="to-studio__fact to-studio__fact--lead">
                <dt class="to-studio__label">{{ __('product_images.balance') }}</dt>
                <dd class="to-studio__value">{{ $spendable }}</dd>
            </div>
            <div class="to-studio__fact">
                <dt class="to-studio__label">{{ __('product_images.review.awaiting') }}</dt>
                <dd class="to-studio__value">{{ number_format($counts['awaiting_review']) }}</dd>
            </div>
            <div class="to-studio__fact">
                <dt class="to-studio__label">{{ __('product_images.review.approved') }}</dt>
                <dd class="to-studio__value">{{ number_format($counts['approved']) }}</dd>
            </div>
            <div class="to-studio__fact">
                <dt class="to-studio__label">{{ __('product_images.review.rejected') }}</dt>
                <dd class="to-studio__value">{{ number_format($counts['rejected']) }}</dd>
            </div>
            <div class="to-studio__fact">
                <dt class="to-studio__label">{{ __('product_images.review.failed') }}</dt>
                <dd class="to-studio__value">{{ number_format($counts['failed']) }}</dd>
            </div>
        </dl>

        {{-- The money contract, stated calmly: a caption under the stats, not an alarm. --}}
        <p class="to-studio__notice">
            <x-filament::icon icon="heroicon-o-information-circle" class="to-studio__notice-icon" />
            <span class="to-studio__notice-text">{{ __('product_images.charge_notice') }}</span>
        </p>
    </x-filament::section>

    {{-- LIVE progress — the batch row is the truth, not the queue. --}}
    @if($activeBatch)
        <x-filament::section>
            <x-slot:heading>{{ __('product_images.progress.heading') }}</x-slot:heading>
            <x-slot:description>
                {{ __('product_images.progress.sub', ['operation' => __('product_images.operation.' . $activeBatch->operation_key)]) }}
            </x-slot:description>

            <div class="to-studio__progress">
                <div class="to-studio__bar" role="progressbar"
                     aria-valuenow="{{ $activeBatch->progressPercent() }}" aria-valuemin="0" aria-valuemax="100">
                    <span @class(['to-studio__bar-fill', 'to-studio__bar-fill--p' . (int) (round($activeBatch->progressPercent() / 10) * 10)])></span>
                </div>

                <p class="to-studio__progress-text">
                    {{ __('product_images.progress.counts', [
                        'settled' => $activeBatch->settled(),
                        'total' => $activeBatch->total,
                        'succeeded' => $activeBatch->succeeded,
                        'failed' => $activeBatch->failed,
                        'skipped' => $activeBatch->skipped,
                    ]) }}
                </p>
            </div>
        </x-filament::section>
    @endif

    {{-- IN PROGRESS — a per-product signal that a render is actually happening (not just a
         counter). The page polls, so a tile leaves this strip and joins Review the moment its
         image is ready. --}}
    @if($processing->isNotEmpty())
        <x-filament::section>
            <x-slot:heading>{{ __('product_images.review.rendering_heading') }}</x-slot:heading>
            <x-slot:description>{{ __('product_images.review.rendering_sub') }}</x-slot:description>

            <div class="to-studio-grid">
                @foreach($processing as $item)
                    <figure class="to-studio-tile" wire:key="proc-{{ $item['id'] }}">
                        <div class="to-studio-tile__frame to-studio-tile__frame--rendering">
                            <div class="to-studio-tile__state">
                                <span class="to-studio-tile__spinner" aria-hidden="true"></span>
                                <span class="to-studio-tile__state-note">{{ __('product_images.tile.rendering') }}</span>
                            </div>
                        </div>
                        <figcaption class="to-studio-tile__caption">
                            <span class="to-studio-tile__name">{{ $item['name'] }}</span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- REVIEW grid. A tile dispatches its signed URL on click; the standalone lightbox host at
         the end of the page (its own Alpine scope) catches it and shows it full-screen. --}}
    <x-filament::section>
        <x-slot:heading>{{ __('product_images.review.heading') }}</x-slot:heading>
        <x-slot:description>{{ __('product_images.review.sub') }}</x-slot:description>

        <div class="to-studio__filters">
            <x-filament::button type="button" size="xs" wire:click="filterBy(null)"
                                :color="$this->reviewFilter === null ? 'primary' : 'gray'">
                {{ __('product_images.review.filter_all') }}
            </x-filament::button>

            @foreach(\App\Models\ProductAsset::REVIEW_STATUSES as $state)
                <x-filament::button type="button" size="xs" wire:click="filterBy('{{ $state }}')"
                                    :color="$this->reviewFilter === $state ? 'primary' : 'gray'">
                    {{ __('product_images.review_status.' . $state) }} ({{ number_format($counts[$state]) }})
                </x-filament::button>
            @endforeach
        </div>

        @if($tiles->isNotEmpty())
            <div class="to-studio-grid">
                @foreach($tiles as $tile)
                    <figure class="to-studio-tile" wire:key="asset-{{ $tile->id }}">
                        <div class="to-studio-tile__frame" x-data="{ broken: false }">
                            @if($tile->imageUrl)
                                {{-- Click to enlarge. If the stored file is gone (e.g. wiped before the
                                     media-volume fix), the img errors and we swap in a clear message
                                     instead of a broken-image glyph. --}}
                                <button type="button" class="to-studio-tile__zoom"
                                        x-show="! broken"
                                        x-on:click="$dispatch('open-lightbox', { src: @js($tile->imageUrl) })"
                                        :title="@js(__('product_images.tile.enlarge'))">
                                    <img class="to-studio-tile__img" src="{{ $tile->imageUrl }}"
                                         alt="{{ $tile->productName }}" loading="lazy"
                                         x-on:error="broken = true" />
                                </button>
                                <div class="to-studio-tile__state" x-show="broken" x-cloak>
                                    <x-filament::icon icon="heroicon-o-photo" />
                                    <span class="to-studio-tile__state-note">{{ __('product_images.tile.broken') }}</span>
                                </div>
                            @else
                                <div class="to-studio-tile__state">
                                    <x-filament::icon icon="heroicon-o-photo" />
                                    <span class="to-studio-tile__state-note">{{ __('product_images.tile.no_image') }}</span>
                                </div>
                            @endif

                            <span @class([
                                'to-badge',
                                'to-badge--success' => $tile->isApproved(),
                                'to-badge--danger' => $tile->isRejected(),
                                'to-badge--info' => ! $tile->isApproved() && ! $tile->isRejected(),
                                'to-studio-tile__badge',
                            ])>
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ __('product_images.review_status.' . $tile->reviewStatus) }}
                            </span>

                            {{-- Edit tools (top-inline-end, on the image): enlarge, guided-regenerate
                                 (Update prompt) and image-to-image Fix. Frosted so they read on any
                                 product photo; shown only when there is a result to act on. --}}
                            @if($tile->imageUrl)
                                <div class="to-studio-tile__tools" x-show="! broken">
                                    <button type="button" class="to-studio-tile__tool"
                                            x-on:click="$dispatch('open-lightbox', { src: @js($tile->imageUrl) })"
                                            title="{{ __('product_images.tile.enlarge') }}"
                                            aria-label="{{ __('product_images.tile.enlarge') }}">
                                        <x-filament::icon icon="heroicon-o-arrows-pointing-out" class="to-studio-tile__tool-glyph" />
                                    </button>
                                    <button type="button" class="to-studio-tile__tool"
                                            wire:click="mountAction('updatePrompt', { asset: {{ $tile->id }} })"
                                            wire:loading.attr="disabled"
                                            title="{{ __('product_images.tile.update_prompt') }}"
                                            aria-label="{{ __('product_images.tile.update_prompt') }}">
                                        <x-filament::icon icon="heroicon-o-pencil-square" class="to-studio-tile__tool-glyph" />
                                    </button>
                                    <button type="button" class="to-studio-tile__tool"
                                            wire:click="mountAction('fixImage', { asset: {{ $tile->id }} })"
                                            wire:loading.attr="disabled"
                                            title="{{ __('product_images.tile.fix_image') }}"
                                            aria-label="{{ __('product_images.tile.fix_image') }}">
                                        <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="to-studio-tile__tool-glyph" />
                                    </button>
                                </div>
                            @endif

                            {{-- The review decision, over a scrim at the bottom of the image — revealed on
                                 hover / keyboard focus (always shown on touch). Approve / Reject, plus the
                                 accent Push / Repush when the store rail applies. Same wire:click actions. --}}
                            <div class="to-studio-tile__bar" role="group"
                                 aria-label="{{ __('product_images.tile.actions_review') }}">
                                <button type="button"
                                        class="to-studio-tile__act to-studio-tile__act--approve to-studio-tile__act--grow"
                                        wire:click="approve({{ $tile->id }})" @disabled($tile->isApproved())>
                                    <x-filament::icon icon="heroicon-o-check" class="to-studio-tile__act-glyph" />
                                    {{ __('product_images.tile.approve') }}
                                </button>

                                <button type="button"
                                        class="to-studio-tile__act to-studio-tile__act--reject to-studio-tile__act--grow"
                                        wire:click="reject({{ $tile->id }})" @disabled($tile->isRejected())>
                                    <x-filament::icon icon="heroicon-o-x-mark" class="to-studio-tile__act-glyph" />
                                    {{ __('product_images.tile.reject') }}
                                </button>

                                @if($tile->canPush())
                                    <button type="button"
                                            class="to-studio-tile__act to-studio-tile__act--publish to-studio-tile__act--icon"
                                            wire:click="mountAction('pushMedia', { asset: {{ $tile->id }} })"
                                            wire:loading.attr="disabled"
                                            title="{{ __('product_images.tile.push') }}"
                                            aria-label="{{ __('product_images.tile.push') }}">
                                        <x-filament::icon icon="heroicon-o-arrow-up-tray" class="to-studio-tile__act-glyph" />
                                    </button>
                                @endif

                                @if($tile->canRePush())
                                    <button type="button"
                                            class="to-studio-tile__act to-studio-tile__act--retry to-studio-tile__act--icon"
                                            wire:click="rePush({{ $tile->id }})"
                                            wire:target="rePush({{ $tile->id }})"
                                            wire:loading.attr="disabled"
                                            title="{{ __('product_images.tile.repush') }}"
                                            aria-label="{{ __('product_images.tile.repush') }}">
                                        <x-filament::icon icon="heroicon-o-arrow-path-rounded-square" class="to-studio-tile__act-glyph" />
                                    </button>
                                @endif
                            </div>
                        </div>

                        <figcaption class="to-studio-tile__caption">
                            <span class="to-studio-tile__name">{{ $tile->productName }}</span>
                            <span class="to-studio-tile__meta">{{ $tile->modelUsed }}</span>
                        </figcaption>

                        {{-- The STORE state: is this image live on the product page, and if not, why not. --}}
                        @if($tile->isShopifyProduct && $tile->pushStatus !== \App\Models\ProductAsset::PUSH_NOT_PUSHED)
                            <p @class([
                                'to-studio-tile__push',
                                'to-studio-tile__push--live' => $tile->isPushed(),
                                'to-studio-tile__push--error' => $tile->isPushFailed(),
                            ])>
                                {{ __('product_images.push_status.' . $tile->pushStatus) }}
                            </p>
                        @endif

                        {{-- Shopify's own mediaUserErrors, verbatim — the merchant's only explanation. --}}
                        @if($tile->isPushFailed() && $tile->pushError)
                            <p class="to-studio-tile__push-error">{{ $tile->pushError }}</p>
                        @endif

                        {{-- Low-emphasis utilities, kept OFF the image for safety. Icon-only; the label
                             lives in title + aria-label. Regenerate SPENDS CREDIT (the money wall is the
                             deterministic intent id in RegenerateProductImage, not this button); Undo
                             restores the PRODUCT's original gallery; Delete removes the generated image
                             for good (not a refund; blocked while live — undo first). --}}
                        <div class="to-studio-tile__actions to-studio-tile__actions--utility" role="group"
                             aria-label="{{ __('product_images.tile.actions_more') }}">
                            <button type="button"
                                    class="to-studio-tile__act to-studio-tile__act--icon to-studio-tile__act--ghost"
                                    wire:click="regenerate({{ $tile->id }})"
                                    wire:target="regenerate({{ $tile->id }})"
                                    wire:loading.attr="disabled"
                                    wire:confirm="{{ __('product_images.tile.regenerate_confirm') }}"
                                    title="{{ __('product_images.tile.regenerate') }}"
                                    aria-label="{{ __('product_images.tile.regenerate') }}">
                                <x-filament::icon icon="heroicon-o-arrow-path" class="to-studio-tile__act-glyph" />
                            </button>

                            @if($tile->canUndo())
                                <button type="button"
                                        class="to-studio-tile__act to-studio-tile__act--icon to-studio-tile__act--ghost"
                                        wire:click="undoProductMedia({{ $tile->productId }})"
                                        wire:target="undoProductMedia({{ $tile->productId }})"
                                        wire:loading.attr="disabled"
                                        wire:confirm="{{ __('product_images.tile.undo_confirm') }}"
                                        title="{{ __('product_images.tile.undo') }}"
                                        aria-label="{{ __('product_images.tile.undo') }}">
                                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="to-studio-tile__act-glyph" />
                                </button>
                            @endif

                            <button type="button"
                                    class="to-studio-tile__act to-studio-tile__act--icon to-studio-tile__act--danger-ghost"
                                    wire:click="deleteAsset({{ $tile->id }})"
                                    wire:target="deleteAsset({{ $tile->id }})"
                                    wire:loading.attr="disabled"
                                    wire:confirm="{{ __('product_images.tile.delete_confirm') }}"
                                    title="{{ __('product_images.tile.delete') }}"
                                    aria-label="{{ __('product_images.tile.delete') }}">
                                <x-filament::icon icon="heroicon-o-trash" class="to-studio-tile__act-glyph" />
                            </button>
                        </div>
                    </figure>
                @endforeach
            </div>
        @else
            <x-to.empty-state
                variant="first-run"
                title="product_images.review.empty"
                sub="product_images.review.empty_sub"
            />
        @endif

    </x-filament::section>

    {{-- Standalone full-screen preview host. It lives in its OWN Alpine scope and the overlay is
         rendered ONLY while an image is open (template x-if), so when closed it is not in the DOM
         at all — it can never dim the page or swallow a click. Esc / a click closes it. --}}
    <div x-data="{ src: null }"
         x-on:open-lightbox.window="src = $event.detail.src"
         x-on:keydown.escape.window="src = null">
        <template x-if="src">
            <div class="to-studio-lightbox" x-on:click="src = null" role="dialog" aria-modal="true">
                <button type="button" class="to-studio-lightbox__close" x-on:click="src = null"
                        :aria-label="@js(__('product_images.lightbox.close'))">
                    <x-filament::icon icon="heroicon-o-x-mark" />
                </button>
                <img class="to-studio-lightbox__img" :src="src" alt="" />
            </div>
        </template>
    </div>
</x-filament-panels::page>
