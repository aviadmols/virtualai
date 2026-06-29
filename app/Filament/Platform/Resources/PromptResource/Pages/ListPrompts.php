<?php

namespace App\Filament\Platform\Resources\PromptResource\Pages;

use App\Filament\Platform\Resources\PromptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * P5 — Prompts index. Lists every prompt scope (global → site) with a header "add
 * prompt" CTA. Platform-only resource; Prompt is global allow-list (no seam).
 */
class ListPrompts extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = PromptResource::class;

    private const ADD_LABEL = 'platform.prompts.add';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__(self::ADD_LABEL))
                ->icon('heroicon-o-plus'),
        ];
    }
}
