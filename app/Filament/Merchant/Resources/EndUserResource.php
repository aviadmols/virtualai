<?php

namespace App\Filament\Merchant\Resources;

use App\Domain\Leads\LeadsExporter;
use App\Filament\Merchant\Resources\EndUserResource\Pages\ListEndUsers;
use App\Filament\Merchant\Resources\EndUserResource\Pages\ViewEndUser;
use App\Models\EndUser;
use App\Support\Ui\StatusBadge;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * M5 / M6 — Leads ("Tray On users") list + lead card (A6 / A7).
 *
 * Tenant-safety: EndUser is BelongsToAccount and the merchant panel is bound to
 * the owner's account, so the list is ALREADY account-scoped — no manual
 * where(account_id), no withoutGlobalScopes(). The CSV export runs through
 * LeadsExporter::download(Account) with the SAME signed-in account, so a merchant
 * can only ever export their own leads.
 *
 * The status badge resolves through StatusBadge (lead funnel machine), never an
 * inline colour. The detail view (ViewEndUser) renders the A7 lead card.
 */
class EndUserResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = EndUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'nav.leads';

    protected static ?int $navigationSort = 1;

    // The lead-funnel machine the badge column resolves through (§5 map).
    private const STATUS_MACHINE = 'lead';

    // i18n label keys (leads.*).
    private const LABEL_SINGULAR = 'leads.singular';
    private const NAV_LABEL = 'leads.title';
    private const EXPORT_LABEL = 'leads.export';

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
                TextColumn::make('full_name')
                    ->label(__('leads.col.name'))
                    ->weight('medium')
                    ->placeholder(__('leads.anonymous'))
                    ->searchable(['full_name', 'email'])
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('leads.col.email'))
                    ->color('gray')
                    ->placeholder(__('leads.col.no_email'))
                    ->searchable(),
                TextColumn::make('phone')
                    ->label(__('leads.col.phone'))
                    ->color('gray')
                    ->placeholder(__('leads.col.no_phone'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('leads.col.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(StatusBadge::label(self::STATUS_MACHINE, $state)))
                    ->color(static fn (string $state): string => self::filamentColor(StatusBadge::tone(self::STATUS_MACHINE, $state))),
                TextColumn::make('generations_used')
                    ->label(__('leads.col.tries'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label(__('leads.col.last_attempt'))
                    ->since()
                    ->placeholder(__('leads.col.never'))
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('leads.col.status'))
                    ->options(self::statusOptions()),
            ])
            ->headerActions([
                Action::make('export')
                    ->label(__(self::EXPORT_LABEL))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(static fn () => app(LeadsExporter::class)->download(Auth::user()->account)),
            ])
            ->recordUrl(static fn (EndUser $record): string => ViewEndUser::getUrl(['record' => $record]))
            ->emptyStateHeading(__('leads.empty'))
            ->emptyStateDescription(__('leads.empty_sub'))
            ->emptyStateIcon('heroicon-o-user-group')
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEndUsers::route('/'),
            'view' => ViewEndUser::route('/{record}'),
        ];
    }

    /** Lead-funnel status → localised label, for the filter select. */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (EndUser::STATUSES as $status) {
            $options[$status] = __(StatusBadge::label(self::STATUS_MACHINE, $status));
        }

        return $options;
    }

    /**
     * Map a StatusBadge tone to the Filament badge colour. The colours themselves
     * come from the brand/status tokens via the theme; this only names the slot.
     */
    private static function filamentColor(string $tone): string
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
