<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Media\MediaStorage;
use App\Filament\Platform\Resources\StylePresetResource\Pages\CreateStylePreset;
use App\Filament\Platform\Resources\StylePresetResource\Pages\EditStylePreset;
use App\Filament\Platform\Resources\StylePresetResource\Pages\ListStylePresets;
use App\Jobs\GenerateStylePresetSampleJob;
use App\Models\StylePreset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Platform — the GLOBAL Style Presets library (super-admin only; /platform is already gated by
 * User::canAccessPanel). Each preset carries a base operation (which sets the surface + model), a
 * prompt, and an uploaded reference image; a sample is generated + approved (Phase 2 actions), and
 * an APPROVED preset then appears in the merchant/shopper style slider.
 *
 * StylePreset is on GlobalModels::ALLOW_LIST (NOT BelongsToAccount), so it reads directly — no
 * seam. The reference image uploads to the private media disk; the sample is shown via a signed URL.
 */
class StylePresetResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = StylePreset::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    // The locked AI nav group in PlatformPanelProvider; sits after models/prompts/operations.
    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 4;

    private const LABEL_SINGULAR = 'platform.style_presets.singular';

    private const NAV_LABEL = 'platform.style_presets.title';

    private const REFERENCE_DIRECTORY = 'style-presets/references';

    public static function getModelLabel(): string
    {
        return __(self::LABEL_SINGULAR);
    }

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('platform.style_presets.field.name'))
                        ->required()
                        ->maxLength(120)
                        ->columnSpanFull(),
                    Select::make('operation_key')
                        ->label(__('platform.style_presets.field.operation'))
                        ->helperText(__('platform.style_presets.field.operation_help'))
                        ->options(self::operationOptions())
                        ->required(),
                    Toggle::make('is_active')
                        ->label(__('platform.style_presets.field.is_active'))
                        ->default(true),
                    Textarea::make('user_prompt')
                        ->label(__('platform.style_presets.field.prompt'))
                        ->helperText(__('platform.style_presets.field.prompt_help'))
                        ->required()
                        ->rows(6)
                        ->maxLength(4000)
                        ->columnSpanFull(),
                    // The reference image drives the sample generation + is the slider thumbnail.
                    FileUpload::make('reference_image_path')
                        ->label(__('platform.style_presets.field.reference'))
                        ->helperText(__('platform.style_presets.field.reference_help'))
                        ->image()
                        ->maxSize(5120)
                        ->disk((string) config('trayon.media.disk'))
                        ->directory(self::REFERENCE_DIRECTORY)
                        ->visibility('private')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $media = app(MediaStorage::class);

        return $table
            ->columns([
                ViewColumn::make('before_after')
                    ->label(__('platform.style_presets.col.sample'))
                    ->view('filament.platform.columns.style-before-after')
                    ->state(static fn (StylePreset $r): array => [
                        'before' => $r->reference_image_path !== null ? $media->signedUrl($r->reference_image_path) : null,
                        'after' => $r->sample_image_path !== null ? $media->signedUrl($r->sample_image_path) : null,
                    ]),
                TextColumn::make('name')
                    ->label(__('platform.style_presets.col.name'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('operation_key')
                    ->label(__('platform.style_presets.col.surface'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => self::surfaceLabel($state)),
                TextColumn::make('sample_status')
                    ->label(__('platform.style_presets.col.sample_status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        StylePreset::SAMPLE_READY => 'success',
                        StylePreset::SAMPLE_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __('platform.style_presets.sample.'.$state)),
                TextColumn::make('status')
                    ->label(__('platform.style_presets.col.status'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === StylePreset::STATUS_APPROVED ? 'success' : 'warning')
                    ->formatStateUsing(static fn (string $state): string => __('platform.style_presets.status.'.$state)),
                IconColumn::make('is_active')
                    ->label(__('platform.style_presets.col.active'))
                    ->boolean(),
            ])
            ->actions([
                self::sampleAction(),
                self::approveAction(),
                self::unapproveAction(),
                EditAction::make(),
            ])
            ->filters([
                SelectFilter::make('operation_key')
                    ->label(__('platform.style_presets.filter.operation'))
                    ->options(self::operationOptions()),
            ])
            // The sample renders async on the worker; poll so the thumbnail + status update live.
            ->poll('10s')
            ->emptyStateHeading(__('platform.style_presets.empty'))
            ->emptyStateDescription(__('platform.style_presets.empty_sub'))
            ->emptyStateIcon('heroicon-o-swatch')
            ->defaultSort('sort');
    }

    /** "Generate sample" — queue the preview render (reuses the playground runner, never charges). */
    private static function sampleAction(): Action
    {
        return Action::make('sample')
            ->label(__('platform.style_presets.action.sample'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->action(static function (StylePreset $record): void {
                $record->update(['sample_status' => StylePreset::SAMPLE_PENDING]);
                GenerateStylePresetSampleJob::dispatch((int) $record->getKey());

                Notification::make()->success()->title(__('platform.style_presets.action.sample_queued'))->send();
            });
    }

    /** "Approve" — the preset becomes selectable in the merchant/shopper slider. */
    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('platform.style_presets.action.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(static fn (StylePreset $record): bool => ! $record->isApproved())
            ->action(static function (StylePreset $record): void {
                $record->update(['status' => StylePreset::STATUS_APPROVED]);

                Notification::make()->success()->title(__('platform.style_presets.action.approved'))->send();
            });
    }

    /** "Unapprove" — pull the preset back out of the sliders. */
    private static function unapproveAction(): Action
    {
        return Action::make('unapprove')
            ->label(__('platform.style_presets.action.unapprove'))
            ->icon('heroicon-o-x-circle')
            ->color('warning')
            ->visible(static fn (StylePreset $record): bool => $record->isApproved())
            ->action(static fn (StylePreset $record) => $record->update(['status' => StylePreset::STATUS_DRAFT]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStylePresets::route('/'),
            'create' => CreateStylePreset::route('/create'),
            'edit' => EditStylePreset::route('/{record}/edit'),
        ];
    }

    /** Operation key → localised option label (only the style-supporting operations). */
    public static function operationOptions(): array
    {
        $options = [];

        foreach (StylePreset::OPERATIONS as $key) {
            $options[$key] = __('platform.style_presets.operation.'.$key);
        }

        return $options;
    }

    /** Operation key → its surface label (Image Studio / Try-On / Banners). */
    public static function surfaceLabel(string $operationKey): string
    {
        $surface = StylePreset::OPERATION_SURFACE[$operationKey] ?? null;

        return $surface !== null ? __('platform.style_presets.surface.'.$surface) : $operationKey;
    }
}
