<?php

namespace App\Filament\Platform\Resources\AiModelResource\Pages;

use App\Filament\Platform\Resources\AiModelResource;
use App\Filament\Platform\Resources\AiModelResource\Pages\Concerns\ConvertsCostHint;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * P4 — Edit an AI model. Folds the USD cost-hint input into the integer micro-USD
 * column before saving (ConvertsCostHint).
 */
class EditAiModel extends EditRecord
{
    use ConvertsCostHint;

    // === CONSTANTS ===
    protected static string $resource = AiModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** @param  array<string,mixed>  $data @return array<string,mixed> */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->foldCostHint($data);
    }
}
