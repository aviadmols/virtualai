<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Credits\CreditMath;
use App\Domain\Platform\PlatformCreditAdjustment;
use App\Filament\Platform\Resources\AccountResource\Pages\ListAccounts;
use App\Filament\Platform\Resources\AccountResource\Pages\ViewAccount;
use App\Models\Account;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * P2 — Accounts (the platform control plane's tenant roster).
 *
 * Account is the TENANT ROOT — NOT BelongsToAccount — so it reads globally and
 * needs NO platform seam (only BelongsToAccount models do). The table is a plain
 * Account::query() that lists every account.
 *
 * Status (active/suspended) is the Account model's own enum, NOT a §5 state-machine
 * badge — its tone lives in STATUS_TONES here and renders via a plain Filament
 * badge colour slot (the same tone→slot bridge the merchant leads list uses).
 *
 * The three control-plane actions go through the AUDITED domain services, never a
 * bare write: suspend/restore call Account::suspend()/restore() (idempotent, write
 * an activity event); credit-adjust calls PlatformCreditAdjustment::apply() (one
 * append-only adjustment ledger row, floored at a zero balance, super-admin gated).
 */
class AccountResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    // Attaches to the locked nav order (Accounts group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.accounts';

    protected static ?int $navigationSort = 1;

    // i18n label keys (platform.accounts.*).
    private const LABEL_SINGULAR = 'platform.accounts.singular';
    private const NAV_LABEL = 'platform.accounts.title';

    // Account status → plain-badge tone (NOT the §5 machine; the account's own enum).
    private const STATUS_TONES = [
        Account::STATUS_ACTIVE => 'success',
        Account::STATUS_SUSPENDED => 'danger',
    ];

    // Action keys (CONST so the table never references a magic string).
    private const ACTION_SUSPEND = 'suspend';
    private const ACTION_RESTORE = 'restore';
    private const ACTION_ADJUST = 'adjust';

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('platform.accounts.col.name'))
                    ->weight('medium')
                    ->description(static fn (Account $r): ?string => $r->company_name)
                    ->searchable(['name', 'company_name', 'billing_email'])
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('platform.accounts.col.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __('platform.accounts.status.'.$state))
                    ->color(static fn (string $state): string => self::filamentColor(self::STATUS_TONES[$state] ?? 'neutral')),
                TextColumn::make('balance_micro_usd')
                    ->label(__('platform.accounts.col.balance'))
                    ->formatStateUsing(static fn (int $state): string => self::usd($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('reserved_micro_usd')
                    ->label(__('platform.accounts.col.reserved'))
                    ->formatStateUsing(static fn (int $state): string => self::usd($state))
                    ->color('gray')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('sites_count')
                    ->label(__('platform.accounts.col.sites'))
                    ->counts('sites')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('platform.accounts.col.created'))
                    ->since()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('platform.accounts.filter.status'))
                    ->options(self::statusOptions()),
            ])
            ->actions([
                ActionGroup::make([
                    self::adjustAction(),
                    self::suspendAction(),
                    self::restoreAction(),
                ]),
            ])
            ->recordUrl(static fn (Account $record): string => ViewAccount::getUrl(['record' => $record]))
            ->emptyStateHeading(__('platform.accounts.empty'))
            ->emptyStateDescription(__('platform.accounts.empty_sub'))
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'view' => ViewAccount::route('/{record}'),
        ];
    }

    /**
     * Suspend — a destructive-tone, confirmed action. Calls Account::suspend()
     * (idempotent; writes an account_suspended event). Visible only for an active
     * account so the menu never shows a no-op.
     */
    private static function suspendAction(): Action
    {
        return Action::make(self::ACTION_SUSPEND)
            ->label(__('platform.accounts.suspend.label'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(static fn (Account $record): bool => $record->isActive())
            ->requiresConfirmation()
            ->modalHeading(__('platform.accounts.suspend.modal'))
            ->modalDescription(__('platform.accounts.suspend.body'))
            ->modalSubmitActionLabel(__('platform.accounts.suspend.confirm'))
            ->form([
                Textarea::make('reason')
                    ->label(__('platform.accounts.suspend.reason'))
                    ->placeholder(__('platform.accounts.suspend.reason_placeholder'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(static function (Account $record, array $data): void {
                $changed = $record->suspend($data['reason'] ?: null);

                Notification::make()
                    ->title(__($changed ? 'platform.accounts.suspend.done' : 'platform.accounts.suspend.noop'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Restore — a confirmed action. Calls Account::restore() (idempotent; writes an
     * account_restored event). Visible only for a suspended account.
     */
    private static function restoreAction(): Action
    {
        return Action::make(self::ACTION_RESTORE)
            ->label(__('platform.accounts.restore.label'))
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(static fn (Account $record): bool => $record->isSuspended())
            ->requiresConfirmation()
            ->modalHeading(__('platform.accounts.restore.modal'))
            ->modalDescription(__('platform.accounts.restore.body'))
            ->modalSubmitActionLabel(__('platform.accounts.restore.confirm'))
            ->action(static function (Account $record): void {
                $changed = $record->restore();

                Notification::make()
                    ->title(__($changed ? 'platform.accounts.restore.done' : 'platform.accounts.restore.noop'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Manual credit adjustment — a form action. Amount is entered in USD and
     * converted to micro-USD. A stable idempotency anchor (a typed reference, else a
     * per-form-open nonce) makes a double-submit collapse to one row. Routes through
     * PlatformCreditAdjustment::apply() (super-admin gated, one append-only adjustment
     * row, floored at zero) — never a bare balance write.
     */
    private static function adjustAction(): Action
    {
        return Action::make(self::ACTION_ADJUST)
            ->label(__('platform.accounts.adjust.label'))
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->modalHeading(__('platform.accounts.adjust.modal'))
            ->modalDescription(__('platform.accounts.adjust.body'))
            ->modalSubmitActionLabel(__('platform.accounts.adjust.confirm'))
            ->form([
                // Stable per-form-open idempotency anchor: a double-submit reuses this
                // same nonce so apply() collapses both clicks into one ledger row; a
                // fresh modal open mints a new nonce (distinct adjustments never collide).
                Hidden::make('idempotency_nonce')
                    ->default(fn (): string => (string) Str::uuid()),
                TextInput::make('amount_usd')
                    ->label(__('platform.accounts.adjust.amount'))
                    ->helperText(__('platform.accounts.adjust.amount_help'))
                    ->numeric()
                    ->required()
                    ->prefix('$')
                    ->step('0.01'),
                TextInput::make('reference')
                    ->label(__('platform.accounts.adjust.reference'))
                    ->helperText(__('platform.accounts.adjust.reference_help'))
                    ->maxLength(120),
                Textarea::make('description')
                    ->label(__('platform.accounts.adjust.description'))
                    ->placeholder(__('platform.accounts.adjust.description_placeholder'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(static function (Account $record, array $data): void {
                $micro = CreditMath::usdToMicro((float) $data['amount_usd']);

                // A typed reference (operator audit note) wins as the idempotency ref;
                // otherwise the stable per-form nonce anchors it — never a fresh
                // per-call UUID, so a double-submit can't double-adjust.
                $row = app(PlatformCreditAdjustment::class)->apply(
                    account: $record,
                    amountMicroUsd: $micro,
                    reference: $data['reference'] ?: $data['idempotency_nonce'],
                    description: $data['description'] ?? '',
                );

                Notification::make()
                    ->title(__('platform.accounts.adjust.done'))
                    ->body(__('platform.accounts.adjust.result', [
                        'balance' => self::usd($row->balance_after_micro_usd),
                        'delta' => self::usd($row->amount_micro_usd),
                    ]))
                    ->success()
                    ->send();
            });
    }

    /** Account status → localised label options, for the filter select. */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (Account::STATUSES as $status) {
            $options[$status] = __('platform.accounts.status.'.$status);
        }

        return $options;
    }

    /** Integer micro-USD → a signed $X.XX display string (display only). */
    public static function usd(int $microUsd): string
    {
        $sign = $microUsd < 0 ? '-' : '';

        return $sign.'$'.number_format(abs(CreditMath::microToUsd($microUsd)), 2);
    }

    /** Map a design-token tone to the Filament badge colour slot (theme colours it). */
    public static function filamentColor(string $tone): string
    {
        return match ($tone) {
            'success' => 'success',
            'warn' => 'warning',
            'danger' => 'danger',
            'info' => 'info',
            default => 'gray',
        };
    }
}
