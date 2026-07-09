<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages;

use App\Filament\Platform\Resources\StoryboardProjectResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditStoryboardProject extends EditRecord
{
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
}
