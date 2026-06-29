<?php

namespace App\Filament\Platform\Resources\ActivityEventResource\Pages;

use App\Filament\Platform\Resources\ActivityEventResource;
use Filament\Resources\Pages\ListRecords;

/**
 * P8 — Activity log index (cross-account, read-only). No header create action: the
 * timeline is append-only and written only by ActivityRecorder. The query runs
 * through the audited PlatformActivityQuery seam on the resource.
 */
class ListActivityEvents extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = ActivityEventResource::class;
}
