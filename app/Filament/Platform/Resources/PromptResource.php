<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\PromptResource\Pages\CreatePrompt;
use App\Filament\Platform\Resources\PromptResource\Pages\EditPrompt;
use App\Filament\Platform\Resources\PromptResource\Pages\ListPrompts;
use App\Models\AiOperation;
use App\Models\Prompt;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Resources\Resource;

/**
 * P5 — Prompts editor (PLATFORM-ONLY). The prompts table mixes platform-global rows
 * (scope=global / product_type, account_id NULL) with tenant-owned rows
 * (scope=account / site, account_id NOT NULL) in ONE table.
 *
 * Prompt is DELIBERATELY NOT BelongsToAccount (TS-OPENROUTER-002) — a fail-closed
 * global scope would hide the global rows too. It is on GlobalModels::ALLOW_LIST,
 * so this resource reads it DIRECTLY (no seam). The owning account_id is shown as a
 * column (null = the global floor). This panel is super-admin-only (the platform
 * gate), so listing every scope is correct here.
 *
 * The Edit page mounts the RESOLVER-PREVIEW panel (the strtr-safe, read-only "which
 * model + prompt wins, and why" surface) — see EditPrompt + the prompt-preview
 * Livewire component.
 */
class PromptResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Prompt::class;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    // Attaches to the locked nav order (AI group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 2;

    // i18n label keys (platform.prompts.*).
    private const LABEL_SINGULAR = 'platform.prompts.singular';
    private const NAV_LABEL = 'platform.prompts.title';

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
            Section::make(__('platform.prompts.section.scope'))
                ->columns(2)
                ->schema([
                    Select::make('scope')
                        ->label(__('platform.prompts.field.scope'))
                        ->options(self::scopeOptions())
                        ->required()
                        ->live(),
                    Select::make('operation_key')
                        ->label(__('platform.prompts.field.operation'))
                        ->options(self::operationOptions())
                        ->required(),
                    TextInput::make('product_type')
                        ->label(__('platform.prompts.field.product_type'))
                        ->helperText(__('platform.prompts.field.product_type_help'))
                        ->maxLength(120)
                        ->visible(static fn (callable $get): bool => $get('scope') === Prompt::SCOPE_PRODUCT_TYPE),
                    TextInput::make('version')
                        ->label(__('platform.prompts.field.version'))
                        ->numeric()
                        ->default(1)
                        ->required(),
                    Toggle::make('is_active')
                        ->label(__('platform.prompts.field.is_active'))
                        ->default(true),
                ]),

            Section::make(__('platform.prompts.section.template'))
                ->schema([
                    Textarea::make('system_prompt')
                        ->label(__('platform.prompts.field.system'))
                        ->rows(4)
                        ->columnSpanFull(),
                    Textarea::make('user_prompt')
                        ->label(__('platform.prompts.field.user'))
                        ->helperText(__('platform.prompts.field.user_help'))
                        ->rows(8)
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope')
                    ->label(__('platform.prompts.col.scope'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => __('platform.prompts.scope.'.$state))
                    ->sortable(),
                TextColumn::make('operation_key')
                    ->label(__('platform.prompts.col.operation'))
                    ->formatStateUsing(static fn (string $state): string => self::operationLabel($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_type')
                    ->label(__('platform.prompts.col.product_type'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('account_id')
                    ->label(__('platform.prompts.col.account'))
                    ->placeholder(__('platform.prompts.col.global'))
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('version')
                    ->label(__('platform.prompts.col.version', ['version' => '']))
                    ->formatStateUsing(static fn (int $state): string => 'v'.$state)
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('platform.prompts.col.active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label(__('platform.prompts.filter.scope'))
                    ->options(self::scopeOptions()),
                SelectFilter::make('operation_key')
                    ->label(__('platform.prompts.filter.operation'))
                    ->options(self::operationOptions()),
            ])
            ->emptyStateHeading(__('platform.prompts.empty'))
            ->emptyStateDescription(__('platform.prompts.empty_sub'))
            ->emptyStateIcon('heroicon-o-command-line')
            ->defaultSort('scope');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrompts::route('/'),
            'create' => CreatePrompt::route('/create'),
            'edit' => EditPrompt::route('/{record}/edit'),
        ];
    }

    /** Scope → localised option label (from the Prompt SCOPE_* CONSTs). */
    public static function scopeOptions(): array
    {
        return [
            Prompt::SCOPE_GLOBAL => __('platform.prompts.scope.global'),
            Prompt::SCOPE_PRODUCT_TYPE => __('platform.prompts.scope.product_type'),
            Prompt::SCOPE_ACCOUNT => __('platform.prompts.scope.account'),
            Prompt::SCOPE_SITE => __('platform.prompts.scope.site'),
        ];
    }

    /** Operation key → localised option label (from the AiOperation CONSTs). */
    public static function operationOptions(): array
    {
        $options = [];

        foreach (AiOperation::KEYS as $key) {
            $options[$key] = self::operationLabel($key);
        }

        return $options;
    }

    /** A human label for an operation key — its DB label, falling back to the key. */
    public static function operationLabel(string $key): string
    {
        return AiOperation::query()->where('operation_key', $key)->value('label') ?: $key;
    }
}
