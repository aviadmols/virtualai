<?php

namespace App\Filament\Merchant\Resources\EndUserResource\Pages;

use App\Filament\Merchant\Resources\EndUserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * M5 — Leads index. A read-only list (leads are captured by the widget, not
 * created in the panel), so there is no header create action. The CSV export
 * lives on the table header (LeadsExporter, account-scoped).
 */
class ListEndUsers extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = EndUserResource::class;

    /** The localised page heading ("Leads"). */
    public function getTitle(): string
    {
        return __('leads.title');
    }
}
