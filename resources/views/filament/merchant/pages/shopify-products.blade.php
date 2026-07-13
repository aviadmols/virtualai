{{--
    Shopify products (Phase 3) — import + live sync progress.

    Three states: not-connected (empty state pointing at the connect screen), an in-flight
    run (live counters, polled), and the imported-catalog counters + recent runs.

    The page provides every value (counters(), activeRun(), recentRuns()); this view only
    renders. No aggregation and no scan logic in Blade.

    TOKENS: shopify-connect.css (.to-shopify-*), badge.css, empty-state.css — no new CSS,
    no inline styles. i18n: shopify.products.*
--}}
@php
    $run = $this->activeRun();
    $counters = $this->counters();
    $runs = $this->recentRuns();
    $truncated = $this->truncatedRun();
@endphp

<x-filament-panels::page>
    @unless($this->isConnected())
        <x-filament::section>
            <div class="to-empty">
                <x-filament::icon icon="heroicon-o-shopping-bag" class="to-empty__icon" />
                <p class="to-empty__title">{{ __('shopify.products.not_connected.heading') }}</p>
                <p class="to-empty__sub">{{ __('shopify.products.not_connected.sub') }}</p>
            </div>
        </x-filament::section>
    @else
        @if($run)
            <x-filament::section>
                <x-slot:heading>{{ __('shopify.products.progress.heading') }}</x-slot:heading>
                <x-slot:description>{{ __('shopify.products.progress.sub') }}</x-slot:description>

                <dl class="to-shopify__facts">
                    <div class="to-shopify__fact">
                        <dt class="to-shopify__label">{{ __('shopify.products.progress.status') }}</dt>
                        <dd class="to-shopify__value">
                            <span class="to-badge to-badge--info">
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ __('shopify.products.run_status.' . $run->status) }}
                            </span>
                        </dd>
                    </div>

                    <div class="to-shopify__fact">
                        <dt class="to-shopify__label">{{ __('shopify.products.progress.mode') }}</dt>
                        <dd class="to-shopify__value">{{ __('shopify.products.mode.' . $run->mode) }}</dd>
                    </div>

                    <div class="to-shopify__fact">
                        <dt class="to-shopify__label">{{ __('shopify.products.progress.seen') }}</dt>
                        <dd class="to-shopify__value">{{ number_format($run->total_seen) }}</dd>
                    </div>

                    <div class="to-shopify__fact">
                        <dt class="to-shopify__label">{{ __('shopify.products.progress.imported') }}</dt>
                        <dd class="to-shopify__value">{{ number_format($run->imported) }}</dd>
                    </div>

                    <div class="to-shopify__fact">
                        <dt class="to-shopify__label">{{ __('shopify.products.progress.updated') }}</dt>
                        <dd class="to-shopify__value">{{ number_format($run->updated) }}</dd>
                    </div>

                    <div class="to-shopify__fact">
                        <dt class="to-shopify__label">{{ __('shopify.products.progress.archived') }}</dt>
                        <dd class="to-shopify__value">{{ number_format($run->archived) }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif

        @if($truncated)
            {{-- The last walk hit the page budget: the catalog is only PARTLY imported, and
                 nothing was archived (an incomplete walk proves nothing about what is gone). --}}
            <x-filament::section>
                <x-slot:heading>{{ __('shopify.products.truncated.heading') }}</x-slot:heading>

                <dl class="to-shopify__facts">
                    <div class="to-shopify__fact to-shopify__fact--wide">
                        <dt class="to-shopify__label">
                            <span class="to-badge to-badge--warn">
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ __('shopify.products.truncated.badge') }}
                            </span>
                        </dt>
                        <dd class="to-shopify__value">
                            <span class="to-shopify__muted">
                                {{ __('shopify.products.truncated.sub', [
                                    'pages' => number_format($truncated->pages),
                                    'seen' => number_format($truncated->total_seen),
                                ]) }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot:heading>{{ __('shopify.products.catalog.heading') }}</x-slot:heading>
            <x-slot:description>{{ __('shopify.products.catalog.sub') }}</x-slot:description>

            <dl class="to-shopify__facts">
                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.products.catalog.imported') }}</dt>
                    <dd class="to-shopify__value">{{ number_format($counters['imported']) }}</dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.products.catalog.draft') }}</dt>
                    <dd class="to-shopify__value">{{ number_format($counters['draft']) }}</dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.products.catalog.confirmed') }}</dt>
                    <dd class="to-shopify__value">{{ number_format($counters['confirmed']) }}</dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.products.catalog.archived') }}</dt>
                    <dd class="to-shopify__value">{{ number_format($counters['archived']) }}</dd>
                </div>
            </dl>
        </x-filament::section>

        @if($runs->isNotEmpty())
            <x-filament::section collapsible collapsed>
                <x-slot:heading>{{ __('shopify.products.history.heading') }}</x-slot:heading>

                <dl class="to-shopify__facts">
                    @foreach($runs as $entry)
                        <div class="to-shopify__fact">
                            <dt class="to-shopify__label">
                                {{ __('shopify.products.mode.' . $entry->mode) }} &middot;
                                {{ $entry->created_at?->translatedFormat('j M Y H:i') }}
                            </dt>
                            <dd class="to-shopify__value">
                                {{ __('shopify.products.history.line', [
                                    'status' => __('shopify.products.run_status.' . $entry->status),
                                    'imported' => $entry->imported,
                                    'updated' => $entry->updated,
                                    'archived' => $entry->archived,
                                    'failed' => $entry->failed,
                                ]) }}

                                @if($entry->isTruncated())
                                    <span class="to-shopify__muted">{{ __('shopify.products.history.truncated') }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif
    @endunless
</x-filament-panels::page>
