<?php

namespace App\Filament\Merchant\Widgets;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DashboardMetricsBuilder;
use App\Filament\Merchant\Concerns\ResolvesShopAccount;
use App\Filament\Merchant\Pages\BuyCredits;
use Filament\Widgets\Widget;

/**
 * M1 / A10 — the low / out-of-credit banner. Derives the credit state from the
 * typed DashboardMetrics (built account-scoped) and decides the banner's tone +
 * copy + dismissibility HERE, in PHP — the view only renders the decision.
 *
 *   spendable <= 0          → DANGER, persistent (a wall; no dismiss).
 *   spendable <= low-thresh → WARN,   dismissible (a nudge).
 *   otherwise               → no banner (the widget renders nothing).
 *
 * The account is the CURRENT SHOP TENANT's (Filament::getTenant()->account); the
 * figures are isolated to it by the builder's bound tenant.
 */
class CreditBannerWidget extends Widget
{
    use ResolvesShopAccount;

    // === CONSTANTS ===
    protected static string $view = 'filament.merchant.widgets.credit-banner';

    protected int|string|array $columnSpan = 'full';

    // The two derived states (StatusBadge credit.* machine).
    public const STATE_EMPTY = 'empty';
    public const STATE_LOW = 'low';

    private const TONE_DANGER = 'danger';
    private const TONE_WARN = 'warn';

    private const TITLE_EMPTY = 'merchant.credit.empty';
    private const TITLE_LOW = 'merchant.credit.low';

    // The buy-credits CTA target — the M7 BuyCredits page (resolved at runtime via
    // buyHref(), since a Filament route URL is not a compile-time constant).

    /**
     * The render-ready banner descriptor, or null when the balance is healthy.
     *
     * @return array{tone:string,title:string,meta:string,buyHref:?string,dismissible:bool}|null
     */
    public function getBanner(): ?array
    {
        $metrics = $this->metrics();

        if ($metrics->isOutOfCredits()) {
            return $this->descriptor(self::TONE_DANGER, self::TITLE_EMPTY, $metrics, dismissible: false);
        }

        if ($metrics->isLowBalance) {
            return $this->descriptor(self::TONE_WARN, self::TITLE_LOW, $metrics, dismissible: true);
        }

        return null;
    }

    /** Whether to render anything at all (healthy balance → no banner). */
    public function hasBanner(): bool
    {
        return $this->getBanner() !== null;
    }

    /** Build one banner descriptor with a pre-formatted balance meta line. */
    private function descriptor(string $tone, string $title, DashboardMetrics $metrics, bool $dismissible): array
    {
        return [
            'tone' => $tone,
            'title' => $title,
            'meta' => __('merchant.credit.balance_meta', ['amount' => $this->usd($metrics->spendableMicroUsd)]),
            'buyHref' => $this->buyHref(),
            'dismissible' => $dismissible,
        ];
    }

    /** The buy-credits CTA target (the M7 BuyCredits page). */
    private function buyHref(): string
    {
        return BuyCredits::getUrl();
    }

    private function metrics(): DashboardMetrics
    {
        return app(DashboardMetricsBuilder::class)->build($this->shopAccount());
    }

    /** Integer micro-USD of selling value → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }
}
