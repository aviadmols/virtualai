<?php

namespace App\Filament\Platform\Resources\AiModelResource\Pages;

use App\Filament\Platform\Resources\AiModelResource;
use App\Filament\Platform\Resources\AiModelResource\Pages\Concerns\ConvertsCostHint;
use Filament\Resources\Pages\CreateRecord;

/**
 * P4 — Create an AI model. Folds the USD cost-hint input into the integer
 * micro-USD column before persisting (ConvertsCostHint).
 */
class CreateAiModel extends CreateRecord
{
    use ConvertsCostHint;

    // === CONSTANTS ===
    protected static string $resource = AiModelResource::class;

    /** @param  array<string,mixed>  $data @return array<string,mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->foldCostHint($data);
    }
}
