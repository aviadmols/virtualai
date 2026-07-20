<?php

namespace App\Filament\Merchant\Resources;

use App\Domain\Sites\StoreCategory;
use App\Filament\Merchant\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Merchant\Resources\SiteResource\Pages\EditSite;
use App\Filament\Merchant\Resources\SiteResource\Pages\ListSites;
use App\Filament\Merchant\Resources\SiteResource\Pages\ViewSite;
use App\Models\Site;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * M2 — Sites list + add-site. The merchant's storefronts.
 *
 * Tenant-safety: Site is BelongsToAccount and the merchant panel is bound to the
 * owner's account by BindMerchantAccount for the whole request, so the index
 * query is ALREADY account-scoped by the global scope. There is NO manual
 * where(account_id) and NO withoutGlobalScopes() here — adding either would
 * either duplicate the scope or (the latter) break isolation.
 *
 * "Status" is a DERIVED setup state (has the widget been wired: selectors present
 * = ready, else pending). Site has no status enum in the state machine, so this is
 * a presentational setup indicator (sites.status.*), NOT a StatusBadge machine.
 */
class SiteResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Site::class;

    // Site IS the Filament tenant, so it can't be tenant-scoped to itself. This resource is
    // the account's "all shops" list (account-scoped by BindMerchantAccount), not per-shop.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    // Single-shop model: this resource is HIDDEN from the merchant nav
    // (shouldRegisterNavigation() = false), so the group/sort are inert — kept only for
    // the platform-side "all shops" list convention. The retired merchant SITES group is
    // no longer in MerchantPanelProvider::NAV_GROUPS.
    protected static ?string $navigationGroup = 'nav.sites';

    protected static ?int $navigationSort = 1;

    // i18n label keys (sites.*) — never a literal string in the resource.
    private const LABEL_TITLE = 'sites.title';

    private const LABEL_SINGULAR = 'sites.singular';

    private const NAV_LABEL = 'sites.title';

    // Derived setup-state tokens + their plain-badge tones (not the §5 machine).
    private const STATE_READY = 'ready';

    private const STATE_PENDING = 'pending';

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

    /**
     * Single-shop model: the Sites list is hidden from the merchant nav. The routes stay
     * registered — ViewSite (the per-shop hub) + EditSite are still reachable by deep-link
     * (e.g. from the Overview), and the Overview widget renders the same hub for the home.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label(__('sites.field.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('domain')
                    ->label(__('sites.field.domain'))
                    ->placeholder(__('sites.field.domain_placeholder'))
                    ->url()
                    ->maxLength(255),
                Select::make('product_category')
                    ->label(__('sites.field.category'))
                    ->helperText(__('sites.field.category_help'))
                    ->options(StoreCategory::options())
                    ->native(false),
                TagsInput::make('allowed_origins')
                    ->label(__('sites.field.origins'))
                    ->placeholder(__('sites.field.origins_placeholder'))
                    ->helperText(__('sites.field.origins_help')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('sites.col.name'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('domain')
                    ->label(__('sites.col.domain'))
                    ->color('gray')
                    ->placeholder(__('sites.col.no_domain'))
                    ->searchable(),
                TextColumn::make('setup_state')
                    ->label(__('sites.col.status'))
                    ->badge()
                    ->state(static fn (Site $site): string => self::setupState($site))
                    ->formatStateUsing(static fn (string $state): string => __('sites.status.'.$state))
                    ->color(static fn (string $state): string => $state === self::STATE_READY ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('sites.col.created'))
                    ->since()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->actions([
                EditAction::make()
                    ->label(__('sites.action.edit')),
            ])
            ->recordUrl(static fn (Site $record): string => ViewSite::getUrl(['record' => $record]))
            ->emptyStateHeading(__('sites.empty'))
            ->emptyStateDescription(__('sites.empty_sub'))
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->defaultSort('created_at', 'desc');
    }

    /** Modify the index query — read-only marker; the global scope does the rest. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'view' => ViewSite::route('/{record}'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }

    /** A site is "ready" once its page selectors are wired (scanned), else pending. */
    private static function setupState(Site $site): string
    {
        return ! empty($site->selectors) ? self::STATE_READY : self::STATE_PENDING;
    }
}
