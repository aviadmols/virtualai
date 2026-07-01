<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Credits\CreditMath;
use App\Filament\Platform\Resources\AiModelResource\Pages\CreateAiModel;
use App\Filament\Platform\Resources\AiModelResource\Pages\EditAiModel;
use App\Filament\Platform\Resources\AiModelResource\Pages\ListAiModels;
use App\Models\AiModel;
use App\Models\AiOperation;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * P4 — AI models catalog (full CRUD). The allow-list of OpenRouter model ids per
 * operation, with the is_default / is_fallback floor the resolver falls back to.
 *
 * AiModel is on GlobalModels::ALLOW_LIST (a platform catalog, NOT BelongsToAccount),
 * so it reads directly — no seam. Cost hints are entered in USD and stored as
 * integer micro-USD (the money unit everywhere); the operation + cost-unit options
 * come from the model CONSTs, never a magic string.
 */
class AiModelResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = AiModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    // Attaches to the locked nav order (AI group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 1;

    // i18n label keys (platform.models.*).
    private const LABEL_SINGULAR = 'platform.models.singular';
    private const NAV_LABEL = 'platform.models.title';

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
                    TextInput::make('model_id')
                        ->label(__('platform.models.field.model_id'))
                        ->helperText(__('platform.models.field.model_id_help'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('label')
                        ->label(__('platform.models.field.label'))
                        ->maxLength(255),
                    Select::make('operation_key')
                        ->label(__('platform.models.field.operation'))
                        ->options(self::operationOptions())
                        ->required(),
                    Select::make('provider')
                        ->label(__('platform.models.field.provider'))
                        ->options(self::providerOptions())
                        ->default(AiModel::PROVIDER_OPENROUTER)
                        ->required()
                        ->helperText(__('platform.models.field.provider_help')),
                    Toggle::make('is_default')
                        ->label(__('platform.models.field.is_default')),
                    Toggle::make('is_fallback')
                        ->label(__('platform.models.field.is_fallback')),
                    TextInput::make('cost_hint_micro_usd')
                        ->label(__('platform.models.field.cost_hint'))
                        ->helperText(__('platform.models.field.cost_hint_help'))
                        ->numeric()
                        ->prefix('$')
                        ->step('0.000001')
                        // Entered + shown in USD; stored as integer micro-USD. Both directions
                        // convert in-field so the price persists (a second conversion on the
                        // page previously nulled it). number_format avoids scientific notation
                        // (e.g. "2.0E-6") that a numeric input would render blank; trailing
                        // zeros are trimmed for a clean "$0.035".
                        ->formatStateUsing(static fn ($state): ?string => ($state === null || $state === '')
                            ? null
                            : rtrim(rtrim(number_format(CreditMath::microToUsd((int) $state), 6, '.', ''), '0'), '.'))
                        ->dehydrateStateUsing(static fn ($state): ?int => ($state === null || $state === '')
                            ? null
                            : CreditMath::usdToMicro((float) $state)),
                    Select::make('cost_unit')
                        ->label(__('platform.models.field.cost_unit'))
                        ->options(self::unitOptions())
                        ->default(AiModel::UNIT_PER_IMAGE),
                    Toggle::make('is_active')
                        ->label(__('platform.models.field.is_active'))
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('model_id')
                    ->label(__('platform.models.col.model_id'))
                    ->weight('medium')
                    ->description(static fn (AiModel $r): ?string => $r->label)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('operation_key')
                    ->label(__('platform.models.col.operation'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => self::operationLabel($state))
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('platform.models.col.provider'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === AiModel::PROVIDER_BYTEPLUS ? 'warning' : 'gray')
                    ->formatStateUsing(static fn (string $state): string => self::providerOptions()[$state] ?? $state)
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label(__('platform.models.col.default'))
                    ->boolean(),
                IconColumn::make('is_fallback')
                    ->label(__('platform.models.col.fallback'))
                    ->boolean(),
                TextColumn::make('cost_hint_micro_usd')
                    ->label(__('platform.models.col.cost_hint'))
                    ->formatStateUsing(static fn (?int $state): string => $state !== null
                        ? '$'.number_format(CreditMath::microToUsd($state), 6)
                        : '—')
                    ->alignEnd()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('platform.models.col.active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('operation_key')
                    ->label(__('platform.models.filter.operation'))
                    ->options(self::operationOptions()),
            ])
            ->emptyStateHeading(__('platform.models.empty'))
            ->emptyStateDescription(__('platform.models.empty_sub'))
            ->emptyStateIcon('heroicon-o-cpu-chip')
            ->defaultSort('operation_key');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiModels::route('/'),
            'create' => CreateAiModel::route('/create'),
            'edit' => EditAiModel::route('/{record}/edit'),
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

    /** Provider id → label (the AiModel PROVIDER_* CONSTs). */
    public static function providerOptions(): array
    {
        return [
            AiModel::PROVIDER_OPENROUTER => 'OpenRouter',
            AiModel::PROVIDER_BYTEPLUS => 'BytePlus (Seedream)',
        ];
    }

    /** Cost-unit → localised label (from the AiModel UNIT_* CONSTs). */
    private static function unitOptions(): array
    {
        return [
            AiModel::UNIT_PER_IMAGE => __('platform.models.unit.per_image'),
            AiModel::UNIT_PER_1K_TOKENS => __('platform.models.unit.per_1k_tokens'),
        ];
    }
}
