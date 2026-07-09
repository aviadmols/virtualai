<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages;

use App\Filament\Platform\Resources\StoryboardProjectResource;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns\ResolvesStoryboardAssetUrls;
use Filament\Resources\Pages\CreateRecord;

class CreateStoryboardProject extends CreateRecord
{
    use ResolvesStoryboardAssetUrls;

    protected static string $resource = StoryboardProjectResource::class;

    /** Stamp the creating admin (created_by). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** Land on the Builder right after creating so the admin can add references + run the pipeline. */
    protected function getRedirectUrl(): string
    {
        return StoryboardBuilder::getUrl(['record' => $this->record]);
    }
}
