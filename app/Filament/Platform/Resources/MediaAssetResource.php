<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\MediaAssetResource\Pages\CreateMediaAsset;
use App\Filament\Platform\Resources\MediaAssetResource\Pages\EditMediaAsset;
use App\Filament\Platform\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Models\MediaAsset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Platform — the GLOBAL media-assets library (super-admin only): fonts, images,
 * video, audio and files uploaded once and served at a STABLE public URL, so
 * they can be referenced anywhere in the system (custom fonts included — each
 * font row hands out a ready-to-paste @font-face block).
 *
 * MediaAsset is on GlobalModels::ALLOW_LIST (no tenant). Files store under the
 * public "media-assets/" prefix — the ONLY non-banner family MediaController's
 * public door serves.
 */
class MediaAssetResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = MediaAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'platform.nav.controls';

    protected static ?int $navigationSort = 30;

    private const LABEL_SINGULAR = 'platform.media_assets.singular';

    private const NAV_LABEL = 'platform.media_assets.title';

    public const UPLOAD_DIRECTORY = 'media-assets';

    private const MAX_UPLOAD_KB = 51200; // 50 MB — the Livewire ceiling (AppServiceProvider)

    private const URL_DISPLAY_LIMIT = 46;

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
                ->schema([
                    TextInput::make('name')
                        ->label(__('platform.media_assets.field.name'))
                        ->helperText(__('platform.media_assets.field.name_help'))
                        ->required()
                        ->maxLength(120),
                    // The file is immutable after create (the URL must stay stable);
                    // replace = delete + upload a new asset.
                    FileUpload::make('file_path')
                        ->label(__('platform.media_assets.field.file'))
                        ->helperText(__('platform.media_assets.field.file_help'))
                        ->required()
                        ->maxSize(self::MAX_UPLOAD_KB)
                        ->disk((string) config('trayon.media.disk'))
                        ->directory(self::UPLOAD_DIRECTORY)
                        ->visibility('public')
                        ->storeFileNamesIn('original_filename')
                        ->rules(['extensions:'.implode(',', array_keys(MediaAsset::EXTENSION_KINDS))])
                        ->hiddenOn('edit'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->state(static fn (MediaAsset $r): ?string => $r->kind === MediaAsset::KIND_IMAGE ? $r->publicUrl() : null)
                    ->square(),
                TextColumn::make('name')
                    ->label(__('platform.media_assets.col.name'))
                    ->weight('medium')
                    ->description(static fn (MediaAsset $r): string => (string) $r->original_filename)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('platform.media_assets.col.kind'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === MediaAsset::KIND_FONT ? 'info' : 'gray')
                    ->formatStateUsing(static fn (string $state): string => __('platform.media_assets.kind.'.$state)),
                TextColumn::make('size_bytes')
                    ->label(__('platform.media_assets.col.size'))
                    ->formatStateUsing(static fn (int $state): string => self::humanSize($state))
                    ->sortable(),
                // The whole point of the screen: the stable link, one click to copy.
                TextColumn::make('public_url')
                    ->label(__('platform.media_assets.col.url'))
                    ->state(static fn (MediaAsset $r): ?string => $r->publicUrl())
                    ->limit(self::URL_DISPLAY_LIMIT)
                    ->copyable()
                    ->copyableState(static fn (MediaAsset $r): ?string => $r->publicUrl())
                    ->copyMessage(__('platform.media_assets.copied'))
                    ->tooltip(__('platform.media_assets.copy_hint')),
                TextColumn::make('created_at')
                    ->label(__('platform.media_assets.col.uploaded'))
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                self::fontCssAction(),
                self::openAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('platform.media_assets.filter.kind'))
                    ->options(self::kindOptions()),
            ])
            ->emptyStateHeading(__('platform.media_assets.empty'))
            ->emptyStateDescription(__('platform.media_assets.empty_sub'))
            ->emptyStateIcon('heroicon-o-folder-open')
            ->defaultSort('created_at', 'desc');
    }

    /** "@font-face" — a ready-to-paste CSS block for a font asset. */
    private static function fontCssAction(): Action
    {
        return Action::make('font_css')
            ->label(__('platform.media_assets.action.font_css'))
            ->icon('heroicon-o-code-bracket')
            ->color('info')
            ->visible(static fn (MediaAsset $record): bool => $record->kind === MediaAsset::KIND_FONT)
            ->fillForm(static fn (MediaAsset $record): array => ['css' => (string) $record->fontFaceCss()])
            ->form([
                Textarea::make('css')
                    ->label(__('platform.media_assets.action.font_css_help'))
                    ->rows(6),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('platform.media_assets.action.close'));
    }

    /** Open the public URL in a new tab. */
    private static function openAction(): Action
    {
        return Action::make('open')
            ->label(__('platform.media_assets.action.open'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(static fn (MediaAsset $record): string => (string) $record->publicUrl())
            ->openUrlInNewTab();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaAssets::route('/'),
            'create' => CreateMediaAsset::route('/create'),
            'edit' => EditMediaAsset::route('/{record}/edit'),
        ];
    }

    /** Kind key → localised filter label. */
    public static function kindOptions(): array
    {
        $options = [];

        foreach (MediaAsset::KINDS as $kind) {
            $options[$kind] = __('platform.media_assets.kind.'.$kind);
        }

        return $options;
    }

    /** Bytes → a short human size for the table. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024).' KB';
        }

        return $bytes.' B';
    }
}
