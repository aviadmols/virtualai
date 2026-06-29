<?php

namespace App\Filament\Platform\Resources\SiteResource\Pages;

use App\Filament\Platform\Resources\SiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * P3 — Sites index (cross-account). The list query runs through the audited
 * PlatformSiteQuery seam on the resource; a super-admin sees every account's sites with
 * its owning account. The header "add site" CTA provisions a site for a chosen account
 * via the audited PlatformSiteWriter.
 */
class ListSites extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    private const ADD_LABEL = 'platform.sites.add';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__(self::ADD_LABEL))
                ->icon('heroicon-o-plus'),
        ];
    }
}
