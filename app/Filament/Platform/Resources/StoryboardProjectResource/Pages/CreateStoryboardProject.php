<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages;

use App\Filament\Platform\Resources\StoryboardProjectResource;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns\HandlesStoryboardProjectForm;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns\ResolvesStoryboardAssetUrls;
use Filament\Resources\Pages\CreateRecord;

class CreateStoryboardProject extends CreateRecord
{
    use HandlesStoryboardProjectForm;
    use ResolvesStoryboardAssetUrls;

    protected static string $resource = StoryboardProjectResource::class;

    /** Stamp the creating admin (created_by) and stash the reference-image pool for the reconcile. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $this->stashReferenceUploads($data);
    }

    protected function afterCreate(): void
    {
        $this->afterStoryboardPersisted();
    }

    /** Land on the Builder right after creating so the admin can watch/generate. */
    protected function getRedirectUrl(): string
    {
        return StoryboardBuilder::getUrl(['record' => $this->record]);
    }

    protected function getFormActions(): array
    {
        return [
            $this->generateActionGroup(),
            ...parent::getFormActions(),
        ];
    }

    protected function persistStoryboardForm(): void
    {
        $this->create();
    }
}
