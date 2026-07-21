{{--
    Shopify store (Phase 2) — connect / disconnect + connection health.

    Three states: platform-not-configured (no app credentials), disconnected (the empty
    state + the connect CTA), and connected (the store facts + live-update health).
    The page provides every value; this view only renders. The access token NEVER
    reaches a Blade — only the granted scopes, the API version and the shop domain do.

    TOKENS: shopify-connect.css (.to-shopify-*), badge.css, empty-state.css. i18n: shopify.*
--}}
@php
    $connection = $this->connection();
    $health = $this->webhookHealth();
    $credentials = $connection?->credentials ?? [];
@endphp

<x-filament-panels::page>
    @unless($this->isPlatformConfigured())
        <x-filament::section>
            <div class="to-empty to-empty--error">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="to-empty__icon" />
                <p class="to-empty__title">{{ __('shopify.not_configured.heading') }}</p>
                <p class="to-empty__sub">{{ __('shopify.not_configured.sub') }}</p>
            </div>
        </x-filament::section>
    @endunless

    @if($connection?->isInstalled())
        {{-- The store-identity card: a warm-gradient initial tile + the domain + status + since. --}}
        <div class="to-shopify-card">
            <span class="to-shopify-card__avatar" aria-hidden="true">{{ mb_substr($connection->shop_domain, 0, 1) }}</span>
            <div class="to-shopify-card__body">
                <p class="to-shopify-card__domain">{{ $connection->shop_domain }}</p>
                <div class="to-shopify-card__meta">
                    @if($connection->needs_reauth)
                        <span class="to-badge to-badge--warn">
                            <span class="to-badge__dot" aria-hidden="true"></span>
                            {{ __('shopify.status.needs_reauth') }}
                        </span>
                    @else
                        <span class="to-badge to-badge--success">
                            <span class="to-badge__dot" aria-hidden="true"></span>
                            {{ __('shopify.status.installed') }}
                        </span>
                    @endif
                    @if($connection->installed_at)
                        <span class="to-shopify-card__since">{{ __('shopify.connected.installed_at') }}: {{ $connection->installed_at->translatedFormat('j M Y') }}</span>
                    @endif
                </div>
            </div>
        </div>

        <x-filament::section>
            <x-slot:heading>{{ __('shopify.connected.heading') }}</x-slot:heading>
            <x-slot:description>{{ __('shopify.connected.sub') }}</x-slot:description>

            <dl class="to-shopify__facts">
                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.connected.status') }}</dt>
                    <dd class="to-shopify__value">
                        @if($connection->needs_reauth)
                            <span class="to-badge to-badge--warn">
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ __('shopify.status.needs_reauth') }}
                            </span>
                        @else
                            <span class="to-badge to-badge--success">
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ __('shopify.status.installed') }}
                            </span>
                        @endif
                    </dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.connected.shop') }}</dt>
                    <dd class="to-shopify__value to-shopify__value--mono">{{ $connection->shop_domain }}</dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.connected.installed_at') }}</dt>
                    <dd class="to-shopify__value">{{ $connection->installed_at?->translatedFormat('j M Y') }}</dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.connected.api_version') }}</dt>
                    <dd class="to-shopify__value to-shopify__value--mono">
                        {{ $credentials[\App\Models\ShopifyConnection::CRED_API_VERSION] ?? config('shopify.api_version') }}
                    </dd>
                </div>

                <div class="to-shopify__fact to-shopify__fact--wide">
                    <dt class="to-shopify__label">{{ __('shopify.connected.scopes') }}</dt>
                    <dd class="to-shopify__value">
                        <ul class="to-shopify__chips">
                            @foreach(array_filter(explode(',', (string) ($credentials[\App\Models\ShopifyConnection::CRED_SCOPES] ?? ''))) as $scope)
                                <li class="to-shopify__chip">{{ trim($scope) }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        @if($connection->needs_reauth)
            <x-filament::section>
                <div class="to-empty to-empty--error">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="to-empty__icon" />
                    <p class="to-empty__title">{{ __('shopify.reauth.heading') }}</p>
                    <p class="to-empty__sub">{{ __('shopify.reauth.sub') }}</p>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot:heading>{{ __('shopify.webhooks.heading') }}</x-slot:heading>
            <x-slot:description>{{ __('shopify.webhooks.sub') }}</x-slot:description>

            <dl class="to-shopify__facts">
                <div class="to-shopify__fact to-shopify__fact--wide">
                    <dt class="to-shopify__label">{{ __('shopify.webhooks.registered') }}</dt>
                    <dd class="to-shopify__value">
                        @if(count($health['topics']) > 0)
                            <ul class="to-shopify__chips">
                                @foreach($health['topics'] as $topic)
                                    <li class="to-shopify__chip">{{ $topic }}</li>
                                @endforeach
                            </ul>
                        @else
                            <span class="to-shopify__muted">{{ __('shopify.webhooks.none') }}</span>
                        @endif
                    </dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.webhooks.last_event') }}</dt>
                    <dd class="to-shopify__value">
                        {{ $health['last_event_at'] ? \Illuminate\Support\Carbon::parse($health['last_event_at'])->diffForHumans() : __('shopify.webhooks.never') }}
                    </dd>
                </div>

                <div class="to-shopify__fact">
                    <dt class="to-shopify__label">{{ __('shopify.webhooks.failed', ['days' => $this->healthWindowDays()]) }}</dt>
                    <dd class="to-shopify__value">
                        @if($health['failed'] > 0)
                            <span class="to-badge to-badge--danger">
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ $health['failed'] }}
                            </span>
                        @else
                            <span class="to-badge to-badge--success">
                                <span class="to-badge__dot" aria-hidden="true"></span>
                                {{ __('shopify.webhooks.healthy') }}
                            </span>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>
    @elseif($this->isPlatformConfigured())
        <x-filament::section>
            <div class="to-empty">
                <x-filament::icon icon="heroicon-o-shopping-bag" class="to-empty__icon" />
                <p class="to-empty__title">{{ __('shopify.disconnected.heading') }}</p>
                <p class="to-empty__sub">{{ __('shopify.disconnected.sub') }}</p>

                <div class="to-shopify__cta">
                    {{ $this->connectAction }}
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
