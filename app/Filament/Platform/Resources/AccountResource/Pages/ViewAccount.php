<?php

namespace App\Filament\Platform\Resources\AccountResource\Pages;

use App\Filament\Platform\Resources\AccountResource;
use App\Models\Account;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * P2 — Account detail. A native Filament infolist (themed via the platform tokens,
 * zero inline CSS) showing the account overview, its credit position, and meta.
 * The money figures are rendered through AccountResource::usd() (display-only
 * micro→USD); nothing is aggregated here. Account reads globally (tenant root).
 */
class ViewAccount extends ViewRecord
{
    // === CONSTANTS ===
    protected static string $resource = AccountResource::class;

    /** The localised page heading — the account name. */
    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('platform.accounts.section.overview'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label(__('platform.accounts.col.name')),
                    TextEntry::make('status')
                        ->label(__('platform.accounts.col.status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __('platform.accounts.status.'.$state))
                        ->color(static fn (string $state): string => AccountResource::filamentColor(
                            $state === Account::STATUS_ACTIVE ? 'success' : 'danger'
                        )),
                    TextEntry::make('created_at')
                        ->label(__('platform.accounts.col.created'))
                        ->dateTime(),
                    TextEntry::make('sites_count')
                        ->label(__('platform.accounts.col.sites'))
                        ->state(static fn (Account $r): int => $r->sites()->count()),
                ]),

            Section::make(__('platform.accounts.section.credit'))
                ->columns(3)
                ->schema([
                    TextEntry::make('balance_micro_usd')
                        ->label(__('platform.accounts.col.balance'))
                        ->formatStateUsing(static fn (int $state): string => AccountResource::usd($state)),
                    TextEntry::make('reserved_micro_usd')
                        ->label(__('platform.accounts.col.reserved'))
                        ->formatStateUsing(static fn (int $state): string => AccountResource::usd($state)),
                    TextEntry::make('spendable')
                        ->label(__('platform.accounts.field.spendable'))
                        ->state(static fn (Account $r): string => AccountResource::usd($r->spendableMicroUsd())),
                ]),

            Section::make(__('platform.accounts.section.meta'))
                ->columns(2)
                ->schema([
                    TextEntry::make('company_name')
                        ->label(__('platform.accounts.field.company'))
                        ->placeholder('—'),
                    TextEntry::make('billing_email')
                        ->label(__('platform.accounts.field.billing_email'))
                        ->placeholder('—'),
                    TextEntry::make('locale')
                        ->label(__('platform.accounts.field.locale')),
                ]),
        ]);
    }
}
