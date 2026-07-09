<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\CreateStoryboardProject;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\EditStoryboardProject;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\ListStoryboardProjects;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\StoryboardBuilder;
use App\Jobs\RunStoryboardPipelineJob;
use App\Models\StoryboardProject;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Storyboard projects — the admin AI pre-production builder (image storyboard; video later).
 *
 * A project (idea + genre + duration + frame interval + aspect) with tagged reference images runs
 * the DB-managed pipeline into a frame-by-frame storyboard the admin then generates + edits. Admin
 * -only (platform panel), non-tenant, never charges. The heavy UX lives on the Builder page.
 */
class StoryboardProjectResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = StoryboardProject::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 6;

    private const NAV_LABEL = 'platform.storyboard.nav';
    private const SINGULAR = 'platform.storyboard.singular';
    private const RUN_STARTED = 'platform.storyboard.run_started';

    // Aspect-ratio choices offered for a project.
    private const ASPECTS = ['16:9', '9:16', '1:1', '4:3', '3:4'];

    // Reference-image pool: dropped images become auto-numbered @image1..@imageN.
    private const MAX_REFERENCE_IMAGES = 9;
    private const MAX_REFERENCE_KB = 5120;
    private const REFERENCE_DIRECTORY = 'storyboard/inputs';

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getModelLabel(): string
    {
        return __(self::SINGULAR);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('platform.storyboard.form.brief'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('platform.storyboard.field.title'))
                        ->required()
                        ->maxLength(255),
                    ViewField::make('story_idea')
                        ->label(__('platform.storyboard.field.story_idea'))
                        ->helperText(__('platform.storyboard.field.story_idea_help'))
                        ->view('filament.platform.storyboard.story-composer')
                        ->required(),
                ]),
            Section::make(__('platform.storyboard.asset.section'))
                ->description(__('platform.storyboard.asset.section_help'))
                ->schema([
                    // Drop images into one pool — each becomes an auto-numbered @image1, @image2 …
                    // (the number IS the tag). Reconciled into StoryboardAsset rows on save by
                    // SyncsNumberedReferenceImages; order = upload order (drag to renumber).
                    FileUpload::make('reference_uploads')
                        ->hiddenLabel()
                        ->helperText(__('platform.storyboard.asset.upload_help'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->maxFiles(self::MAX_REFERENCE_IMAGES)
                        ->maxSize(self::MAX_REFERENCE_KB)
                        ->disk((string) config('trayon.media.disk'))
                        ->directory(self::REFERENCE_DIRECTORY)
                        ->visibility('private')
                        ->columnSpanFull(),
                ]),
            Section::make(__('platform.storyboard.form.advanced'))
                ->description(__('platform.storyboard.form.advanced_help'))
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('genre')
                        ->label(__('platform.storyboard.field.genre'))
                        ->placeholder(__('platform.storyboard.field.genre_placeholder')),
                    Select::make('aspect_ratio')
                        ->label(__('platform.storyboard.field.aspect'))
                        ->options(array_combine(self::ASPECTS, self::ASPECTS))
                        ->default('16:9')
                        ->selectablePlaceholder(false),
                    TextInput::make('duration_seconds')
                        ->label(__('platform.storyboard.field.duration'))
                        ->numeric()
                        ->minValue(3)
                        ->maxValue(600)
                        ->default(15)
                        ->required(),
                    TextInput::make('frame_interval_seconds')
                        ->label(__('platform.storyboard.field.interval'))
                        ->helperText(__('platform.storyboard.field.interval_help'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(30)
                        ->default(3)
                        ->required(),
                    TextInput::make('resolution')
                        ->label(__('platform.storyboard.field.resolution'))
                        ->placeholder('1080p'),
                    TextInput::make('platform_target')
                        ->label(__('platform.storyboard.field.platform')),
                    Textarea::make('visual_style')
                        ->label(__('platform.storyboard.field.visual_style'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('platform.storyboard.col.title'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('platform.storyboard.col.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __('platform.storyboard.status.'.$state))
                    ->color(static fn (string $state): string => match ($state) {
                        StoryboardProject::STATUS_READY => 'success',
                        StoryboardProject::STATUS_FAILED => 'danger',
                        StoryboardProject::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('frames_count')
                    ->label(__('platform.storyboard.col.frames'))
                    ->counts('frames')
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label(__('platform.storyboard.col.updated'))
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Action::make('builder')
                    ->label(__('platform.storyboard.open_builder'))
                    ->icon('heroicon-o-squares-2x2')
                    ->url(static fn (StoryboardProject $record): string => StoryboardBuilder::getUrl(['record' => $record])),
                Action::make('run')
                    ->label(__('platform.storyboard.run'))
                    ->icon('heroicon-o-play')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(static function (StoryboardProject $record): void {
                        RunStoryboardPipelineJob::dispatch($record->id);
                        Notification::make()->success()->title(__(self::RUN_STARTED))->send();
                    }),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('platform.storyboard.empty'))
            ->emptyStateDescription(__('platform.storyboard.empty_sub'))
            ->emptyStateIcon('heroicon-o-film')
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        // References are managed inline on the form (upload + tag alongside the story), so no
        // separate relation manager is needed.
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoryboardProjects::route('/'),
            'create' => CreateStoryboardProject::route('/create'),
            'edit' => EditStoryboardProject::route('/{record}/edit'),
            'builder' => StoryboardBuilder::route('/{record}/builder'),
        ];
    }
}
