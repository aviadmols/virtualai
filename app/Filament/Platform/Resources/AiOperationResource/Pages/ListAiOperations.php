<?php

namespace App\Filament\Platform\Resources\AiOperationResource\Pages;

use App\Filament\Platform\Resources\AiOperationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * P6 — AI operations index. Lists the control-plane operations with a header "add"
 * CTA. AiOperation is global allow-list (no seam).
 */
class ListAiOperations extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = AiOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus'),
        ];
    }
}
