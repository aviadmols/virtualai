<?php

namespace App\Filament\Platform\Resources\AiOperationResource\Pages;

use App\Filament\Platform\Resources\AiOperationResource;
use App\Filament\Platform\Resources\AiOperationResource\Pages\Concerns\ConvertsEstimatedCost;
use Filament\Resources\Pages\CreateRecord;

/**
 * P6 — Create an AI operation. Folds the USD estimated-cost input into the integer
 * micro-USD column before persisting (ConvertsEstimatedCost).
 */
class CreateAiOperation extends CreateRecord
{
    use ConvertsEstimatedCost;

    // === CONSTANTS ===
    protected static string $resource = AiOperationResource::class;

    /** @param  array<string,mixed>  $data @return array<string,mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->foldEstimatedCost($data);
    }
}
