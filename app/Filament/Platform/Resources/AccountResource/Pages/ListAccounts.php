<?php

namespace App\Filament\Platform\Resources\AccountResource\Pages;

use App\Filament\Platform\Resources\AccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * P2 — Accounts index. A read list of every account (Account is the tenant root,
 * read globally — no seam). The header "add account" CTA provisions an account + its
 * owner login (PlatformAccountProvisioner); the per-row edit/suspend/restore/adjust
 * actions live on the resource.
 */
class ListAccounts extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = AccountResource::class;

    private const ADD_LABEL = 'platform.accounts.add';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__(self::ADD_LABEL))
                ->icon('heroicon-o-plus'),
        ];
    }
}
