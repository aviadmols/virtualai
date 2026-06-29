<?php

namespace App\Filament\Platform\Resources\PlatformCreditLedgerResource\Pages;

use App\Filament\Platform\Resources\PlatformCreditLedgerResource;
use Filament\Resources\Pages\ListRecords;

/**
 * P7 — Credit ledger index (cross-account, read-only). No header create action:
 * the ledger is append-only and written only by CreditLedgerService. The query
 * runs through the audited PlatformCreditLedgerQuery seam on the resource.
 */
class ListPlatformCreditLedger extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = PlatformCreditLedgerResource::class;
}
