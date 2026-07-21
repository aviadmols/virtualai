<?php

namespace App\Filament\Platform\Resources\StylePresetResource\Pages;

use App\Filament\Platform\Resources\StylePresetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStylePreset extends EditRecord
{
    protected static string $resource = StylePresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
