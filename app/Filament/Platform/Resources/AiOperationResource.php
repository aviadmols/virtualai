<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Credits\CreditMath;
use App\Filament\Platform\Resources\AiOperationResource\Pages\CreateAiOperation;
use App\Filament\Platform\Resources\AiOperationResource\Pages\EditAiOperation;
use App\Filament\Platform\Resources\AiOperationResource\Pages\ListAiOperations;
use App\Models\AiModel;
use App\Models\AiOperation;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * P6 — AI operations config (CRUD). One row per operation (product_scan,
 * try_on_generation): default/fallback model, image quality, aspect ratio,
 * retention, estimated cost, credit_multiplier override.
 *
 * AiOperation is on GlobalModels::ALLOW_LIST (a platform catalog, NOT
 * BelongsToAccount), so it reads directly — no seam. The default/fallback model
 * options are drawn from the AiModel catalog for the SAME operation_key (so an
 * operation can only point at an allow-listed model). Estimated cost is entered in
 * USD and stored as integer micro-USD; the multiplier override is optional (null =
 * the config default markup).
 */
class AiOperationResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = AiOperation::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    // Attaches to the locked nav order (AI group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 3;

    // i18n label keys (platform.operations.*).
    private const LABEL_SINGULAR = 'platform.operations.singular';
    private const NAV_LABEL = 'platform.operations.title';

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
            Section::make(__('platform.operations.section.operation'))
                ->columns(2)
                ->schema([
                    Select::make('operation_key')
                        ->label(__('platform.operations.field.operation_key'))
                        ->options(self::operationKeyOptions())
                        ->required()
                        ->live(),
                    TextInput::make('label')
                        ->label(__('platform.operations.field.label'))
                        ->maxLength(255),
                ]),

            Section::make(__('platform.operations.section.models'))
                ->columns(2)
                ->schema([
                    Select::make('default_model')
                        ->label(__('platform.operations.field.default_model'))
                        ->options(static fn (callable $get): array => self::modelOptions($get('operation_key')))
                        ->searchable(),
                    Select::make('fallback_model')
                        ->label(__('platform.operations.field.fallback_model'))
                        ->options(static fn (callable $get): array => self::modelOptions($get('operation_key')))
                        ->searchable(),
                ]),

            Section::make(__('platform.operations.section.image'))
                ->columns(3)
                ->schema([
                    TextInput::make('image_quality')
                        ->label(__('platform.operations.field.quality'))
                        ->maxLength(60),
                    TextInput::make('aspect_ratio')
                        ->label(__('platform.operations.field.aspect'))
                        ->maxLength(20),
                    TextInput::make('retention_days')
                        ->label(__('platform.operations.field.retention'))
                        ->helperText(__('platform.operations.field.retention_help'))
                        ->numeric(),
                ]),

            Section::make(__('platform.operations.section.pricing'))
                ->columns(2)
                ->schema([
                    TextInput::make('estimated_cost_usd')
                        ->label(__('platform.operations.field.estimated_cost'))
                        ->numeric()
                        ->prefix('$')
                        ->step('0.000001')
                        ->afterStateHydrated(static function (TextInput $component, $state, ?AiOperation $record): void {
                            $component->state($record?->estimated_cost_micro_usd !== null && $record !== null
                                ? CreditMath::microToUsd((int) $record->estimated_cost_micro_usd)
                                : null);
                        })
                        ->dehydrated(false),
                    TextInput::make('credit_multiplier')
                        ->label(__('platform.operations.field.multiplier'))
                        ->helperText(__('platform.operations.field.multiplier_help'))
                        ->numeric()
                        ->step('0.001')
                        ->suffix('×'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operation_key')
                    ->label(__('platform.operations.col.operation'))
                    ->weight('medium')
                    ->description(static fn (AiOperation $r): ?string => $r->label)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('default_model')
                    ->label(__('platform.operations.col.default_model'))
                    ->placeholder('—')
                    ->color('gray'),
                TextColumn::make('fallback_model')
                    ->label(__('platform.operations.col.fallback_model'))
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('image_quality')
                    ->label(__('platform.operations.col.quality'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('aspect_ratio')
                    ->label(__('platform.operations.col.aspect'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('credit_multiplier')
                    ->label(__('platform.operations.col.multiplier'))
                    ->formatStateUsing(static fn (?string $state): string => $state !== null ? $state.'×' : '—')
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('platform.operations.empty'))
            ->emptyStateDescription(__('platform.operations.empty_sub'))
            ->emptyStateIcon('heroicon-o-adjustments-horizontal')
            ->defaultSort('operation_key');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiOperations::route('/'),
            'create' => CreateAiOperation::route('/create'),
            'edit' => EditAiOperation::route('/{record}/edit'),
        ];
    }

    /** The two operation keys (from the AiOperation CONSTs) → localised labels. */
    public static function operationKeyOptions(): array
    {
        $options = [];

        foreach (AiOperation::KEYS as $key) {
            $options[$key] = $key;
        }

        return $options;
    }

    /** Allow-listed model ids for an operation (catalog rows) → option labels. */
    public static function modelOptions(?string $operationKey): array
    {
        if ($operationKey === null || $operationKey === '') {
            return [];
        }

        return AiModel::query()
            ->forOperation($operationKey)
            ->orderBy('model_id')
            ->pluck('model_id', 'model_id')
            ->all();
    }
}
