<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\RelationManagers;

use App\Models\StoryboardAsset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Reference manager — the project's tagged reference images (@main_character, @location_pool …).
 * The tag is what the story prompt references; the pipeline binds it to the uploaded image. Files
 * land on the media disk (private); referenced images are fed to the frame generator.
 */
class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'References';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('tag')
                ->label(__('platform.storyboard.asset.tag'))
                ->helperText(__('platform.storyboard.asset.tag_help'))
                ->prefix('@')
                ->required()
                ->maxLength(64),
            Select::make('type')
                ->label(__('platform.storyboard.asset.type'))
                ->options(array_combine(StoryboardAsset::TYPES, StoryboardAsset::TYPES))
                ->default(StoryboardAsset::TYPE_CHARACTER)
                ->required(),
            FileUpload::make('file_path')
                ->label(__('platform.storyboard.asset.image'))
                ->image()
                ->maxSize(5120)
                ->disk((string) config('trayon.media.disk'))
                ->directory('storyboard/inputs')
                ->visibility('private')
                ->columnSpanFull(),
            TextInput::make('description')
                ->label(__('platform.storyboard.asset.description'))
                ->columnSpanFull(),
            TextInput::make('reference_strength')
                ->label(__('platform.storyboard.asset.strength'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(70),
            Toggle::make('keep_exact')
                ->label(__('platform.storyboard.asset.keep_exact')),
            Toggle::make('is_locked')
                ->label(__('platform.storyboard.asset.lock')),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('file_path')
                    ->label(__('platform.storyboard.asset.image'))
                    ->disk((string) config('trayon.media.disk'))
                    ->height(48),
                TextColumn::make('tag')
                    ->label(__('platform.storyboard.asset.tag'))
                    ->formatStateUsing(static fn (string $state): string => '@'.$state)
                    ->weight('medium'),
                TextColumn::make('type')
                    ->label(__('platform.storyboard.asset.type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('reference_strength')
                    ->label(__('platform.storyboard.asset.strength'))
                    ->suffix('%')
                    ->alignEnd(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('platform.storyboard.asset.empty'));
    }
}
