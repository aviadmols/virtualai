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

        <div class="to-studio__notice">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="to-studio__notice-icon" />
            <p class="to-studio__notice-text">{{ __('product_images.charge_notice') }}</p>
        </div>

        <dl class="to-studio__facts">
            <div class="to-studio__fact">
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

                        <div class="to-studio-tile__actions">
                            <x-filament::button type="button" size="xs" color="success" icon="heroicon-o-check"
                                                wire:click="approve({{ $tile->id }})" :disabled="$tile->isApproved()">
                                {{ __('product_images.tile.approve') }}
                            </x-filament::button>

                            <x-filament::button type="button" size="xs" color="danger" icon="heroicon-o-x-mark"
                                                wire:click="reject({{ $tile->id }})" :disabled="$tile->isRejected()">
                                {{ __('product_images.tile.reject') }}
                            </x-filament::button>

                            {{--
                                Regenerate SPENDS CREDIT. The confirm + the disable-while-pending are
                                courtesy, NOT the guard: the money wall is the deterministic intent id
                                in RegenerateProductImage (two clicks -> one asset, one charge).
                            --}}
                            <x-filament::button type="button" size="xs" color="gray" icon="heroicon-o-arrow-path"
                                                wire:click="regenerate({{ $tile->id }})"
                                                wire:target="regenerate({{ $tile->id }})"
                                                wire:loading.attr="disabled"
                                                wire:confirm="{{ __('product_images.tile.regenerate_confirm') }}">
                                {{ __('product_images.tile.regenerate') }}
                            </x-filament::button>

                            {{--
                                PUSH is FREE (no AI, no credit). The placement chooser opens with the
                                product's REAL gallery; the wall against a double-clicked push is the
                                row-locked claim + the persisted shopify_media_id, never this button.
                            --}}
                            @if($tile->canPush())
                                <x-filament::button type="button" size="xs" color="primary" icon="heroicon-o-arrow-up-tray"
                                                    wire:click="mountAction('pushMedia', { asset: {{ $tile->id }} })"
                                                    wire:loading.attr="disabled">
                                    {{ __('product_images.tile.push') }}
                                </x-filament::button>
                            @endif

                            @if($tile->canRePush())
                                <x-filament::button type="button" size="xs" color="warning" icon="heroicon-o-arrow-path-rounded-square"
                                                    wire:click="rePush({{ $tile->id }})"
                                                    wire:target="rePush({{ $tile->id }})"
                                                    wire:loading.attr="disabled">
                                    {{ __('product_images.tile.repush') }}
                                </x-filament::button>
                            @endif

                            {{-- UNDO restores this PRODUCT's original gallery (order + main image). --}}
                            @if($tile->canUndo())
                                <x-filament::button type="button" size="xs" color="gray" icon="heroicon-o-arrow-uturn-left"
                                                    wire:click="undoProductMedia({{ $tile->productId }})"
                                                    wire:target="undoProductMedia({{ $tile->productId }})"
                                                    wire:loading.attr="disabled"
                                                    wire:confirm="{{ __('product_images.tile.undo_confirm') }}">
                                    {{ __('product_images.tile.undo') }}
                                </x-filament::button>
                            @endif

                            {{-- DELETE removes this generated image for good (media file + row). Not a
                                 refund. Blocked while the image is live in the store (undo first). --}}
                            <x-filament::button type="button" size="xs" color="danger" icon="heroicon-o-trash"
                                                wire:click="deleteAsset({{ $tile->id }})"
                                                wire:target="deleteAsset({{ $tile->id }})"
                                                wire:loading.attr="disabled"
                                                wire:confirm="{{ __('product_images.tile.delete_confirm') }}">
                                {{ __('product_images.tile.delete') }}
                            </x-filament::button>
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
