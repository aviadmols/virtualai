<?php

namespace App\Filament\Platform\Resources\AiOperationResource\Pages;

use App\Filament\Platform\Resources\AiOperationResource;
use App\Filament\Platform\Resources\AiOperationResource\Pages\Concerns\ConvertsEstimatedCost;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * P6 — Edit an AI operation. Folds the USD estimated-cost input into the integer
 * micro-USD column before saving (ConvertsEstimatedCost).
 */
class EditAiOperation extends EditRecord
{
    use ConvertsEstimatedCost;

    // === CONSTANTS ===
    protected static string $resource = AiOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** @param  array<string,mixed>  $data @return array<string,mixed> */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->foldEstimatedCost($data);
    }

    /** A fal-catalog model picked from the full registry is auto-catalogued with its provider. */
    protected function afterSave(): void
    {
        AiOperationResource::ensureModelsCatalogued($this->record);
    }
}
