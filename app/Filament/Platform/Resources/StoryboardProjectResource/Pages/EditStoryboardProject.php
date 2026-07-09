<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages;

use App\Filament\Platform\Resources\StoryboardProjectResource;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns\HandlesStoryboardProjectForm;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns\ResolvesStoryboardAssetUrls;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditStoryboardProject extends EditRecord
{
    use HandlesStoryboardProjectForm;
    use ResolvesStoryboardAssetUrls;

    protected static string $resource = StoryboardProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('builder')
                ->label(__('platform.storyboard.open_builder'))
                ->icon('heroicon-o-squares-2x2')
                ->url(fn (): string => StoryboardBuilder::getUrl(['record' => $this->record])),
        ];
    }

    /** Prefill the reference-image pool from the saved (ordered) assets. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->hydrateReferenceUploads($data, $this->record);
    }

    /** Stash the reference-image pool for the auto-number reconcile after save. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stashReferenceUploads($data);
    }

    protected function afterSave(): void
    {
        $this->afterStoryboardPersisted();
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
        $this->save(shouldRedirect: false);
        $this->redirectToBuilder();
    }
}
