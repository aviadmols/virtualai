{{--
    M1 / A10 — the low / out-of-credit banner view. The widget already decided
    the tone, copy and dismissibility from DashboardMetrics; this view only
    renders that decision (or nothing when the balance is healthy).

    TOKENS: credit-banner.css (via <x-to.credit-banner>).
    i18n: merchant.credit.* (resolved inside the component).
--}}
<x-filament-widgets::widget>
    @php($banner = $this->getBanner())
    @if($banner)
        <x-to.credit-banner
            :tone="$banner['tone']"
            :title="$banner['title']"
            :meta="$banner['meta']"
            :buyHref="$banner['buyHref']"
            :dismissible="$banner['dismissible']"
        />
    @endif
</x-filament-widgets::widget>
