<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Ai\ProviderRouter;
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
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

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

    // Per-model "Test" action (no-spend probe) i18n + reason -> localized body map.
    private const LABEL_TEST = 'platform.models.test';
    private const LABEL_TEST_OK = 'platform.models.test_ok';
    private const LABEL_TEST_FAIL = 'platform.models.test_fail';
    private const TEST_RESULT_VIEW = 'filament.platform.model-test-result';
    private const TEST_REASON_BODY = [
        'ok' => 'platform.models.test_ok_body',
        'not_configured' => 'platform.models.test_not_configured',
        'invalid_key' => 'platform.models.test_invalid_key',
        'model_not_found' => 'platform.models.test_not_found',
        'timeout' => 'platform.models.test_timeout',
        'error' => 'platform.models.test_error',
    ];

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
                        ->live()
                        ->helperText(__('platform.models.field.provider_help')),
                    // Per-model region host — a BytePlus account may serve different models from
                    // different regions (e.g. Seedream 4.5 on ap-southeast). Blank = the default host.
                    TextInput::make('base_url')
                        ->label(__('platform.models.field.base_url'))
                        ->helperText(__('platform.models.field.base_url_help'))
                        ->url()
                        ->maxLength(255)
                        ->placeholder('https://ark.ap-southeast.bytepluses.com/api/v3')
                        ->visible(fn (Get $get): bool => $get('provider') === AiModel::PROVIDER_BYTEPLUS)
                        ->columnSpanFull(),
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
            ->actions([
                // No-spend probe of THIS model against its provider (at its region host) — a
                // details modal shows the EXACT provider response, so the admin never runs a real
                // try-on to find a 404 and can see precisely why a model is/ isn't reachable.
                Action::make('test')
                    ->label(__(self::LABEL_TEST))
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->modalHeading(fn (AiModel $record): string => __(self::LABEL_TEST).' — '.$record->model_id)
                    ->modalIcon('heroicon-o-signal')
                    ->modalContent(fn (AiModel $record) => view(self::TEST_RESULT_VIEW, ['result' => self::probeModel($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('platform.models.test_close')),
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

    /**
     * Probe a single model against its provider (no-spend, at its per-model region host) and
     * return a localized result + the EXACT provider response for the details modal. The
     * provider clients never throw; only ProviderRouter::for can (unknown provider), so guard it.
     *
     * @return array{ok: bool, title: string, body: string, raw: string}
     */
    public static function probeModel(AiModel $record): array
    {
        try {
            $result = app(ProviderRouter::class)->for($record->provider)->checkModel($record->model_id, null, $record->base_url);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'reason' => 'error', 'message' => $e->getMessage(), 'detail' => null];
        }

        $bodyKey = self::TEST_REASON_BODY[$result['reason']] ?? null;

        return [
            'ok' => (bool) $result['ok'],
            'title' => __($result['ok'] ? self::LABEL_TEST_OK : self::LABEL_TEST_FAIL, ['model' => $record->model_id]),
            'body' => $bodyKey !== null ? __($bodyKey, ['model' => $record->model_id]) : (string) ($result['message'] ?? ''),
            // The EXACT provider message + raw response body (host + HTTP status + payload).
            'raw' => trim(((string) ($result['message'] ?? ''))."\n\n".((string) ($result['detail'] ?? ''))),
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
