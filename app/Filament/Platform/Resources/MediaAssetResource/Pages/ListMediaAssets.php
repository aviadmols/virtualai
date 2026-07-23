<?php

namespace App\Filament\Platform\Resources\MediaAssetResource\Pages;

use App\Filament\Platform\Resources\MediaAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMediaAssets extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('platform.media_assets.action.upload')),
        ];
    }
}
