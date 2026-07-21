<?php

namespace App\Filament\Platform\Resources\StylePresetResource\Pages;

use App\Filament\Platform\Resources\StylePresetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStylePresets extends ListRecords
{
    protected static string $resource = StylePresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->icon('heroicon-o-plus'),
        ];
    }
}
