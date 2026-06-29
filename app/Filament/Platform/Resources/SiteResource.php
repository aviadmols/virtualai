<?php

namespace App\Filament\Platform\Resources;

use App\Domain\Platform\PlatformSiteQuery;
use App\Filament\Platform\Resources\SiteResource\Pages\ListSites;
use App\Models\Site;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * P3 — Sites (cross-account, READ-ONLY).
 *
 * Site IS BelongsToAccount, so a bare Site::query() in the platform panel (which
 * has NO bound tenant for a super-admin) would fail closed and return EMPTY. The
 * table query therefore goes through the AUDITED super-admin seam
 * PlatformSiteQuery::withAccount() — the ONE sanctioned withoutGlobalScope path,
 * guarded by PlatformGuard (super-admin only). There is NO inline
 * withoutGlobalScopes() here.
 *
 * Read-only: no create/edit/delete pages — platform site config is the merchant's;
 * this surface only lists every site and which account owns it. "Setup" is the same
 * derived ready/pending indicator the merchant sites list uses (selectors present =
 * ready), NOT a state-machine badge.
 */
class SiteResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    // Attaches to the locked nav order (Sites group) in PlatformPanelProvider.
    protected static ?string $navigationGroup = 'platform.nav.sites';

    protected static ?int $navigationSort = 1;

    // i18n label keys (platform.sites.*).
    private const LABEL_SINGULAR = 'platform.sites.singular';
    private const NAV_LABEL = 'platform.sites.title';

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('platform.sites.col.name'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('account.name')
                    ->label(__('platform.sites.col.account'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('domain')
                    ->label(__('platform.sites.col.domain'))
                    ->color('gray')
                    ->placeholder(__('platform.sites.col.no_domain'))
                    ->searchable(),
                TextColumn::make('setup_state')
                    ->label(__('platform.sites.col.state'))
                    ->badge()
                    ->state(static fn (Site $site): string => self::setupState($site))
                    ->formatStateUsing(static fn (string $state): string => __('platform.sites.state.'.$state))
                    ->color(static fn (string $state): string => $state === self::STATE_READY ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('platform.sites.col.created'))
                    ->since()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('platform.sites.empty'))
            ->emptyStateDescription(__('platform.sites.empty_sub'))
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The table query is the AUDITED cross-account seam (super-admin guarded),
     * eager-loading the owning account. This is the ONLY sanctioned bypass of the
     * BelongsToAccount global scope — never an inline withoutGlobalScopes() here.
     */
    public static function getEloquentQuery(): Builder
    {
        return PlatformSiteQuery::withAccount();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
        ];
    }

    /** A site is "ready" once its page selectors are wired (scanned), else pending. */
    private static function setupState(Site $site): string
    {
        return ! empty($site->selectors) ? self::STATE_READY : self::STATE_PENDING;
    }
}
