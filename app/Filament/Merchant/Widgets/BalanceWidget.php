<?php

namespace App\Filament\Merchant\Widgets;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DashboardMetricsBuilder;
use App\Models\Account;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * M7 / A1 — the credit-balance band above the ledger. Reads the typed
 * DashboardMetrics snapshot (built account-scoped) and hands each <x-to.kpi> a
 * PRE-FORMATTED value — this widget NEVER aggregates a number in Blade.
 *
 * Three cards: spendable (balance − reserved), balance, reserved. The account is
 * the authenticated owner's (auth()->user()->account), never another account; the
 * builder runs every query inside that bound tenant, so the figures are isolated.
 */
class BalanceWidget extends Widget
{
    // === CONSTANTS ===
    protected static string $view = 'filament.merchant.widgets.balance';

    protected int|string|array $columnSpan = 'full';

    // The card i18n labels + sub-labels (credits.kpi.*).
    private const LABEL_SPENDABLE = 'credits.kpi.spendable';
    private const LABEL_BALANCE = 'credits.kpi.balance';
    private const LABEL_RESERVED = 'credits.kpi.reserved';
    private const SUB_SPENDABLE = 'credits.kpi.spendable_sub';
    private const SUB_RESERVED = 'credits.kpi.reserved_sub';

    // Tone per card (StatusBadge vocabulary → the KPI accent edge).
    private const TONE_SUCCESS = 'success';
    private const TONE_WARN = 'warn';
    private const TONE_NEUTRAL = 'neutral';
    private const TONE_INFO = 'info';

    /**
     * The three balance cards as a flat, render-ready array. Each entry carries an
     * i18n label key, a pre-formatted $ value string, a tone, and an optional sub.
     *
     * @return array<int,array{label:string,value:string,tone:string,sub:?string}>
     */
    public function getCards(): array
    {
        $metrics = $this->metrics();

        return [
            [
                'label' => self::LABEL_SPENDABLE,
                'value' => $this->usd($metrics->spendableMicroUsd),
                'tone' => $metrics->isLowBalance ? self::TONE_WARN : self::TONE_SUCCESS,
                'sub' => self::SUB_SPENDABLE,
            ],
            [
                'label' => self::LABEL_BALANCE,
                'value' => $this->usd($metrics->balanceMicroUsd),
                'tone' => self::TONE_NEUTRAL,
                'sub' => null,
            ],
            [
                'label' => self::LABEL_RESERVED,
                'value' => $this->usd($metrics->reservedMicroUsd),
                'tone' => self::TONE_INFO,
                'sub' => self::SUB_RESERVED,
            ],
        ];
    }

    /** The account-scoped snapshot for the signed-in merchant. */
    private function metrics(): DashboardMetrics
    {
        return app(DashboardMetricsBuilder::class)->build($this->account());
    }

    /** The authenticated account-owner's account (never another account). */
    private function account(): Account
    {
        return Auth::user()->account;
    }

    /** Integer micro-USD of selling value → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }
}
