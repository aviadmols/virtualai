<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns;

use App\Domain\Media\MediaStorage;
use App\Models\StoryboardProject;
use Livewire\Attributes\Renderless;

/**
 * Exposes the saved reference images as a { tag => signedUrl } map for the Story-idea composer's
 * @-mention picker + gallery thumbnails. Called from Alpine ($wire.getStoryboardAssetUrls()).
 * Renderless — pure data, never re-renders the form. Empty on Create (nothing saved yet).
 */
trait ResolvesStoryboardAssetUrls
{
    #[Renderless]
    public function getStoryboardAssetUrls(): array
    {
        $record = isset($this->record) ? $this->record : null;

        if (! $record instanceof StoryboardProject) {
            return [];
        }

        $media = app(MediaStorage::class);

        return $record->assets()
            ->whereNotNull('file_path')
            ->get()
            ->mapWithKeys(fn ($asset): array => [(string) $asset->tag => $media->signedUrl($asset->file_path)])
            ->all();
    }
}
