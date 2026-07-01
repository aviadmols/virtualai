<?php

namespace App\Filament\Merchant\Resources;

use App\Domain\Credits\CreditMath;
use App\Filament\Merchant\Resources\CreditLedgerResource\Pages\ListCreditLedger;
use App\Models\CreditLedger;
use App\Support\Ui\StatusBadge;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * M7 / A11 — the merchant credit ledger (READ-ONLY, account-scoped).
 *
 * CreditLedger IS BelongsToAccount and the merchant panel is bound to the owner's
 * account (BindMerchantAccount), so the table is ALREADY isolated to one account —
 * no manual where(account_id), no withoutGlobalScopes(). The ledger is APPEND-ONLY
 * (CreditLedgerService is its only writer), so there is NO create / edit / delete:
 * a fresh account shows only its opening grant row. A correction is a NEW row written
 * by the service, never an edit here.
 *
 * The type badge resolves through StatusBadge (ledger machine, §5 map), never an
 * inline colour. Money is integer micro-USD; amounts render signed (display only).
 */
class CreditLedgerResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = CreditLedger::class;

    // Credits are per-ACCOUNT (no site_id) — shared across all the merchant's shops. Not
    // tenant-scoped; the account global scope (BindMerchantAccount) already isolates it.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    // Attaches to the locked nav order (Credits group) in MerchantPanelProvider.
    protected static ?string $navigationGroup = 'nav.credits';

    protected static ?int $navigationSort = 1;

    // The ledger machine the badge column resolves through (§5 map).
    private const STATUS_MACHINE = 'ledger';

    // i18n label keys (credits.*).
    private const LABEL_SINGULAR = 'credits.singular';
    private const NAV_LABEL = 'credits.ledger.title';

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
                TextColumn::make('created_at')
                    ->label(__('credits.ledger.col.date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('credits.ledger.col.type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(StatusBadge::label(self::STATUS_MACHINE, $state)))
                    ->color(static fn (string $state): string => self::filamentColor(StatusBadge::tone(self::STATUS_MACHINE, $state))),
                TextColumn::make('amount_micro_usd')
                    ->label(__('credits.ledger.col.amount'))
                    ->formatStateUsing(static fn (int $state): string => self::signedUsd($state))
                    ->color(static fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('balance_after_micro_usd')
                    ->label(__('credits.ledger.col.balance_after'))
                    ->formatStateUsing(static fn (int $state): string => self::usd($state))
                    ->alignEnd(),
                TextColumn::make('reference')
                    ->label(__('credits.ledger.col.reference'))
                    ->state(static fn (CreditLedger $r): string => self::reference($r))
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('credits.ledger.filter.type'))
                    ->options(self::typeOptions()),
            ])
            ->emptyStateHeading(__('credits.ledger.empty'))
            ->emptyStateDescription(__('credits.ledger.empty_sub'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditLedger::route('/'),
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
            CreditLedger::REFERENCE_GENERATION => __('credits.ledger.reference.generation', ['id' => $row->reference_id]),
            CreditLedger::REFERENCE_PURCHASE => __('credits.ledger.reference.purchase', ['id' => $row->reference_id]),
            default => __('credits.ledger.reference.none'),
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
