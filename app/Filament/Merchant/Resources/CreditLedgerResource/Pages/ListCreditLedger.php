<?php

namespace App\Filament\Merchant\Resources\CreditLedgerResource\Pages;

use App\Filament\Merchant\Resources\CreditLedgerResource;
use App\Filament\Merchant\Widgets\BalanceWidget;
use App\Filament\Merchant\Pages\BuyCredits;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * M7 / A11 — the credit-ledger list. Mounts the A1 balance band as a header widget
 * (spendable / balance / reserved) and a primary "Buy credits" header action linking
 * to the BuyCredits page. The list itself is read-only + account-scoped (the resource
 * forbids create/edit/delete; the global scope isolates it to the bound account).
 */
class ListCreditLedger extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = CreditLedgerResource::class;

    // i18n keys.
    private const ACTION_BUY = 'credits.buy.title';

    /** The A1 balance band sits above the ledger table. */
    protected function getHeaderWidgets(): array
    {
        return [
            BalanceWidget::class,
        ];
    }

    /** The primary "Buy credits" CTA in the page header. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('buy')
                ->label(__(self::ACTION_BUY))
                ->icon('heroicon-o-plus-circle')
                ->url(BuyCredits::getUrl()),
        ];
    }
}
