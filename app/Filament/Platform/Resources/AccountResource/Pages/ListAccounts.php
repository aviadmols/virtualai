<?php

namespace App\Filament\Platform\Resources\AccountResource\Pages;

use App\Filament\Platform\Resources\AccountResource;
use Filament\Resources\Pages\ListRecords;

/**
 * P2 — Accounts index. A read list of every account (Account is the tenant root,
 * read globally — no seam). No create action: accounts are created by merchant
 * sign-up, never from the platform panel. The per-row suspend/restore/adjust
 * actions live on the resource.
 */
class ListAccounts extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = AccountResource::class;
}
