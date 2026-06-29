{{--
    A10 — Low / out-of-credit banner.
    The TONE + the copy are decided by the caller from DashboardMetrics (the
    credit state is derived in PHP, never in this view). Two shapes:
      tone=warn   → low balance, dismissible (Alpine-local nudge).
      tone=danger → out of credits, persistent (no dismiss).
    A "Buy credits" CTA is always present; dismiss only on the warn banner.

    Props:
      tone     warn|danger (from StatusBadge credit.* — caller resolves)
      title    i18n key for the headline (merchant.credit.low|empty)
      meta     (optional) i18n key for a sub line (e.g. the balance)
      buyHref  target for the buy-credits CTA
      dismissible  bool — only the warn banner is

    TOKENS: credit-banner.css. i18n: merchant.credit.*, actions.dismiss
--}}
@props([
    'tone' => 'warn',
    'title',
    'meta' => null,
    'buyHref' => null,
    'dismissible' => false,
])
<div
    @if($dismissible) x-data="{ shown: true }" x-show="shown" @endif
    {{ $attributes->class(['to-credit-banner', 'to-credit-banner--' . $tone]) }}
    role="status"
>
    <x-filament::icon
        icon="heroicon-o-exclamation-triangle"
        class="to-credit-banner__icon"
    />

    <div class="to-credit-banner__body">
        <span class="to-credit-banner__title">{{ __($title) }}</span>
        @if($meta)
            <span class="to-credit-banner__meta">{{ __($meta) }}</span>
        @endif
    </div>

    <div class="to-credit-banner__actions">
        @if($buyHref)
            <x-to.cta :label="'merchant.credit.buy_cta'" variant="primary" :href="$buyHref" />
        @endif

        @if($dismissible)
            <button
                type="button"
                class="to-credit-banner__dismiss"
                x-on:click="shown = false"
                :aria-label="@js(__('actions.dismiss'))"
            >
                <x-filament::icon icon="heroicon-o-x-mark" class="to-credit-banner__icon" />
            </button>
        @endif
    </div>
</div>
