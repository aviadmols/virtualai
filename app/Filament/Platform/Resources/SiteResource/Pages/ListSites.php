<?php

namespace App\Filament\Platform\Resources\SiteResource\Pages;

use App\Filament\Platform\Resources\SiteResource;
use Filament\Resources\Pages\ListRecords;

/**
 * P3 — Sites index (cross-account, read-only). No header create action: platform
 * sites are read; the query runs through the audited PlatformSiteQuery seam on the
 * resource. A super-admin sees every account's sites with its owning account.
 */
class ListSites extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;
}
