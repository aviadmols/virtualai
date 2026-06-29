<?php

namespace App\Filament\Platform\Resources\AiModelResource\Pages;

use App\Filament\Platform\Resources\AiModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * P4 — AI models index. The catalog list with a header "add model" CTA. AiModel is
 * global allow-list (no seam).
 */
class ListAiModels extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = AiModelResource::class;

    private const ADD_LABEL = 'platform.models.add';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__(self::ADD_LABEL))
                ->icon('heroicon-o-plus'),
        ];
    }
}
