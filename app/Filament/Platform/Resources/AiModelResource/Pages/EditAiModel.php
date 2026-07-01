<?php

namespace App\Filament\Platform\Resources\AiModelResource\Pages;

use App\Filament\Platform\Resources\AiModelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * P4 — Edit an AI model. The cost hint converts USD <-> integer micro-USD entirely in
 * the form field (formatStateUsing / dehydrateStateUsing on AiModelResource), so this
 * page performs NO cost mutation — a second conversion here nulled the price on save.
 */
class EditAiModel extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = AiModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
