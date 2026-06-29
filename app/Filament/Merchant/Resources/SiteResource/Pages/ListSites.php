<?php

namespace App\Filament\Merchant\Resources\SiteResource\Pages;

use App\Filament\Merchant\Resources\SiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * M2 — Sites index. A read list of the account's storefronts with a header
 * "add site" CTA that leads into the create/onboarding flow. The query is
 * account-scoped by the merchant tenant binding (no manual filter here).
 */
class ListSites extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    private const ADD_LABEL = 'sites.add';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__(self::ADD_LABEL))
                ->icon('heroicon-o-plus'),
        ];
    }
}
