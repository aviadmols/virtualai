<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Credits\CreditMath;
use App\Domain\Platform\PlatformCreditLedgerQuery;
use App\Filament\Platform\Resources\PlatformCreditLedgerResource\Pages\ListPlatformCreditLedger;
use App\Models\CreditLedger;
use App\Support\Ui\StatusBadge;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * P7 — Credits admin (READ-ONLY, cross-account).
 *
 * CreditLedger IS BelongsToAccount and the ledger is APPEND-ONLY (CreditLedgerService
 * is its only writer). The table query goes through the AUDITED super-admin seam
 * PlatformCreditLedgerQuery::withAccount() — the one sanctioned withoutGlobalScope
 * path, guarded by PlatformGuard. There is NO inline withoutGlobalScopes() here, and
 * NO create/edit/delete: a correction is a NEW ledger row written by the service
 * (e.g. a platform credit-adjust on the Accounts screen), never an edit here.
 *
 * The type badge resolves through StatusBadge (ledger machine, §5 map), never an
 * inline colour. Money is integer micro-USD; amounts render signed (display only).
 */
class PlatformCreditLedgerResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = CreditLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    // Attaches to the locked nav order (Controls group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.controls';

    protected static ?int $navigationSort = 2;

    // The ledger machine the badge column resolves through (§5 map).
    private const STATUS_MACHINE = 'ledger';

    // i18n label keys (platform.credits.*).
    private const LABEL_SINGULAR = 'platform.credits.singular';
    private const NAV_LABEL = 'platform.credits.title';

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

    // --- READ-ONLY: no create / edit / delete on the append-only ledger. ---
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account.name')
                    ->label(__('platform.credits.col.account'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('platform.credits.col.type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(StatusBadge::label(self::STATUS_MACHINE, $state)))
                    ->color(static fn (string $state): string => self::filamentColor(StatusBadge::tone(self::STATUS_MACHINE, $state))),
                TextColumn::make('amount_micro_usd')
                    ->label(__('platform.credits.col.amount'))
                    ->formatStateUsing(static fn (int $state): string => self::signedUsd($state))
                    ->color(static fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('balance_after_micro_usd')
                    ->label(__('platform.credits.col.balance_after'))
                    ->formatStateUsing(static fn (int $state): string => self::usd($state))
                    ->alignEnd(),
                TextColumn::make('reference')
                    ->label(__('platform.credits.col.reference'))
                    ->state(static fn (CreditLedger $r): string => self::reference($r))
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('actual_cost_micro_usd')
                    ->label(__('platform.credits.col.cost'))
                    ->formatStateUsing(static fn (?int $state): string => $state !== null ? self::usd($state) : '—')
                    ->color('gray')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('platform.credits.col.date'))
                    ->dateTime()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('platform.credits.filter.type'))
                    ->options(self::typeOptions()),
            ])
            ->emptyStateHeading(__('platform.credits.empty'))
            ->emptyStateDescription(__('platform.credits.empty_sub'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The table query is the AUDITED cross-account seam (super-admin guarded),
     * eager-loading the owning account. The ONLY sanctioned bypass of the
     * BelongsToAccount global scope — never an inline withoutGlobalScopes() here.
     */
    public static function getEloquentQuery(): Builder
    {
        return PlatformCreditLedgerQuery::withAccount();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformCreditLedger::route('/'),
        ];
    }

    /** Ledger type → localised label options, for the filter select (§5 map). */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (CreditLedger::TYPES as $type) {
            $options[$type] = __(StatusBadge::label(self::STATUS_MACHINE, $type));
        }

        return $options;
    }

    /** A row's reference subject as a localised label (generation / purchase / none). */
    private static function reference(CreditLedger $row): string
    {
        return match ($row->reference_type) {
            CreditLedger::REFERENCE_GENERATION => __('platform.credits.reference.generation', ['id' => $row->reference_id]),
            CreditLedger::REFERENCE_PURCHASE => __('platform.credits.reference.purchase', ['id' => $row->reference_id]),
            default => __('platform.credits.reference.none'),
        };
    }

    /** Integer micro-USD → an unsigned $X.XX string (display only). */
    private static function usd(int $microUsd): string
    {
        return '$'.number_format(abs(CreditMath::microToUsd($microUsd)), 2);
    }

    /** Integer micro-USD → a SIGNED ±$X.XX string (display only). */
    private static function signedUsd(int $microUsd): string
    {
        $sign = $microUsd < 0 ? '-' : '+';

        return $sign.'$'.number_format(abs(CreditMath::microToUsd($microUsd)), 2);
    }

    /** Map a StatusBadge tone to the Filament badge colour slot (theme colours it). */
    private static function filamentColor(string $tone): string
    {
        return match ($tone) {
            'success' => 'success',
            'warn' => 'warning',
            'danger' => 'danger',
            'info' => 'info',
            'ink' => 'gray',
            default => 'gray',
        };
    }
}
