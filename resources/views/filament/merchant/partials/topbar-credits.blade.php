{{--
    Topbar credit chip + Buy CTA (merchant panel). Shows the shop's spendable balance and a
    persistent link to top up; the pill turns warn-toned when the balance is low/empty. Tenant-
    scoped and null-guarded — the login / tenant-less routes render nothing. TOKENS:
    topbar-credits.css (.to-topbar-credits*). No inline CSS, no money math in the view beyond the
    single boundary format.
--}}
@php
    $tenant = \Filament\Facades\Filament::getTenant();
    $account = $tenant?->account;
@endphp

@if ($account)
    @php
        $metrics = app(\App\Domain\Reporting\DashboardMetricsBuilder::class)->build($account);
        $isLow = $metrics->isOutOfCredits() || $metrics->isLowBalance;
        $amount = '$' . number_format(\App\Domain\Credits\CreditMath::microToUsd($metrics->spendableMicroUsd), 2);
        $buyUrl = \App\Filament\Merchant\Pages\BuyCredits::getUrl();
    @endphp

    <div class="to-topbar-credits">
        <a href="{{ $buyUrl }}"
           @class(['to-topbar-credits__pill', 'to-topbar-credits__pill--low' => $isLow])
           title="{{ __('credits.topbar.balance') }}">
            <x-filament::icon icon="heroicon-m-banknotes" class="to-topbar-credits__icon" />
            <span class="to-topbar-credits__amount">{{ $amount }}</span>
        </a>

        <a href="{{ $buyUrl }}" class="to-topbar-credits__buy">
            <x-filament::icon icon="heroicon-m-plus" class="to-topbar-credits__buy-icon" />
            <span class="to-topbar-credits__buy-label">{{ __('credits.topbar.buy') }}</span>
        </a>
    </div>
@endif
