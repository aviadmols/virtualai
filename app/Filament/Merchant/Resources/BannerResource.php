<?php

namespace App\Filament\Merchant\Resources;

use App\Domain\Banners\BannerRules;
use App\Domain\Media\MediaStorage;
use App\Filament\Merchant\Resources\BannerResource\Pages\CreateBanner;
use App\Filament\Merchant\Resources\BannerResource\Pages\EditBanner;
use App\Filament\Merchant\Resources\BannerResource\Pages\ListBanners;
use App\Filament\Merchant\Widgets\BannerCandidatesWidget;
use App\Models\Banner;
use App\Models\BannerEvent;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Livewire;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Banners — the merchant "Marketing" resource. AI-generated promotional banners the merchant
 * places on their storefront (Phase 3) with display rules (Phase 4) and per-banner analytics
 * (Phase 5). This phase: create a banner, generate its image from a brief (EditBanner header
 * action), choose a candidate, set the click target + optional text overlay, and activate.
 *
 * Tenant-safety: Banner is BelongsToAccount and the panel is bound to the owner's account, and
 * getEloquentQuery() further narrows to the ACTIVE shop (Filament tenant) — the EndUserResource
 * idiom, no manual where(account_id), no withoutGlobalScopes(). Every write routes through the
 * single validated writer (BannerService) on the Create/Edit pages.
 */
class BannerResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Banner::class;

    // Explicitly scoped to the ACTIVE shop in getEloquentQuery, on top of the account scope.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'nav.marketing';

    protected static ?int $navigationSort = 1;

    // The rolling window (days) the list's clicks/impressions/CTR columns aggregate over.
    private const STATS_WINDOW_DAYS = 30;

    // i18n keys — never a literal in the resource.
    private const LABEL_SINGULAR = 'banners.singular';
    private const LABEL_PLURAL = 'banners.plural';
    private const NAV_LABEL = 'banners.nav';

    /** Narrow the banner list to the ACTIVE shop (Filament tenant) on top of the account scope. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        return $tenant instanceof \App\Models\Site
            ? $query->where('site_id', $tenant->getKey())
            : $query;
    }

    public static function getModelLabel(): string
    {
        return __(self::LABEL_SINGULAR);
    }

    // Wire the localized plural so the breadcrumb/heading don't fall back to Filament's
    // English pluralizer (which appends an "s" to the Hebrew label → "באנרs").
    public static function getPluralModelLabel(): string
    {
        return __(self::LABEL_PLURAL);
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
            Section::make(__('banners.singular'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('banners.field.name'))
                        ->helperText(__('banners.field.name_help'))
                        ->required()
                        ->maxLength(\App\Domain\Banners\BannerContent::NAME_MAX),
                    Select::make('composition')
                        ->label(__('banners.field.composition'))
                        ->helperText(__('banners.field.composition_help'))
                        ->options(self::compositionOptions())
                        ->default(Banner::COMPOSITION_IMAGE)
                        ->required()
                        ->live()
                        ->visibleOn('edit'),
                    TextInput::make('target_url')
                        ->label(__('banners.field.target_url'))
                        ->helperText(__('banners.field.target_url_help'))
                        ->url()
                        ->maxLength(\App\Domain\Banners\BannerContent::TARGET_URL_MAX)
                        ->visibleOn('edit')
                        ->columnSpanFull(),
                    TextInput::make('alt_text')
                        ->label(__('banners.field.alt_text'))
                        ->helperText(__('banners.field.alt_text_help'))
                        ->maxLength(\App\Domain\Banners\BannerContent::ALT_TEXT_MAX)
                        ->visibleOn('edit')
                        ->columnSpanFull(),
                ]),

            // The live candidate gallery — generation progress + clickable thumbnails, right here
            // in the form (NOT a dropdown). A self-polling Livewire component that owns selection
            // (BannerService::selectAsset); there is no selected_asset_id form control.
            Livewire::make(BannerCandidatesWidget::class, fn (?Banner $record): array => ['record' => $record])
                ->visibleOn('edit'),

            Section::make(__('banners.overlay.section'))
                ->description(__('banners.overlay.section_help'))
                // Two independent guards on the two separate slots (isHidden OR !isVisible):
                // hidden on create, and — on edit — visible ONLY for the overlay composition.
                // (visibleOn('edit') would OVERWRITE the composition closure; both set isVisible.)
                ->hiddenOn('create')
                ->visible(static fn (callable $get): bool => $get('composition') === Banner::COMPOSITION_OVERLAY)
                ->columns(2)
                ->schema([
                    TextInput::make('overlay.headline')
                        ->label(__('banners.overlay.headline'))
                        ->maxLength(\App\Domain\Banners\BannerContent::HEADLINE_MAX),
                    TextInput::make('overlay.cta_label')
                        ->label(__('banners.overlay.cta_label'))
                        ->maxLength(\App\Domain\Banners\BannerContent::CTA_LABEL_MAX),
                    TextInput::make('overlay.subtext')
                        ->label(__('banners.overlay.subtext'))
                        ->maxLength(\App\Domain\Banners\BannerContent::SUBTEXT_MAX)
                        ->columnSpanFull(),
                ]),

            Section::make(__('banners.rules.section'))
                ->description(__('banners.rules.section_help'))
                ->visibleOn('edit')
                ->columns(2)
                ->schema([
                    Select::make('rules.audience')
                        ->label(__('banners.rules.audience'))
                        ->helperText(__('banners.rules.audience_help'))
                        ->options(self::audienceOptions())
                        ->default(BannerRules::AUDIENCE_ANY)
                        ->native(false),
                    Select::make('rules.pages.context')
                        ->label(__('banners.rules.pages_context'))
                        ->options(self::pageContextOptions())
                        ->default(BannerRules::PAGE_ANY)
                        ->native(false),
                    TextInput::make('rules.pages.url_contains')
                        ->label(__('banners.rules.url_contains'))
                        ->helperText(__('banners.rules.url_contains_help'))
                        ->maxLength(BannerRules::URL_CONTAINS_MAX)
                        ->columnSpanFull(),
                    DateTimePicker::make('rules.schedule.starts_at')
                        ->label(__('banners.rules.starts_at'))
                        ->seconds(false),
                    DateTimePicker::make('rules.schedule.ends_at')
                        ->label(__('banners.rules.ends_at'))
                        ->seconds(false),
                    TextInput::make('rules.frequency.max_per_session')
                        ->label(__('banners.rules.max_per_session'))
                        ->helperText(__('banners.rules.max_per_session_help'))
                        ->numeric()
                        ->minValue(BannerRules::FREQUENCY_MAX_MIN)
                        ->maxValue(BannerRules::FREQUENCY_MAX_MAX)
                        ->default(0),
                    CheckboxList::make('rules.locales')
                        ->label(__('banners.rules.locales'))
                        ->helperText(__('banners.rules.locales_help'))
                        ->options(self::localeOptions())
                        ->columns(2),
                ]),
        ]);
    }

    /** audience value => localized label. */
    public static function audienceOptions(): array
    {
        $out = [];
        foreach (BannerRules::AUDIENCES as $a) {
            $out[$a] = __('banners.rules.audience_option.'.$a);
        }

        return $out;
    }

    /** page-context value => localized label. */
    public static function pageContextOptions(): array
    {
        $out = [];
        foreach (BannerRules::PAGE_CONTEXTS as $c) {
            $out[$c] = __('banners.rules.page_option.'.$c);
        }

        return $out;
    }

    /** locale value => localized label. */
    public static function localeOptions(): array
    {
        $out = [];
        foreach (BannerRules::LOCALES as $l) {
            $out[$l] = __('banners.rules.locale.'.$l);
        }

        return $out;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('artwork')
                    ->label(__('banners.col.artwork'))
                    ->getStateUsing(static fn (Banner $record): ?string => app(MediaStorage::class)->publicUrl($record->image_path))
                    ->height(40),
                TextColumn::make('name')
                    ->label(__('banners.col.name'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('banners.col.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __('banners.status_option.'.$state))
                    ->color(static fn (string $state): string => self::statusColor($state)),
                TextColumn::make('composition')
                    ->label(__('banners.col.composition'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => __('banners.composition_option.'.$state)),
                TextColumn::make('clicks_count')
                    ->label(__('banners.col.clicks'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('impressions_count')
                    ->label(__('banners.col.impressions'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('ctr')
                    ->label(__('banners.col.ctr'))
                    ->state(static fn (Banner $record): string => ((int) ($record->impressions_count ?? 0)) > 0
                        ? round(((int) $record->clicks_count) / ((int) $record->impressions_count) * 100, 1).'%'
                        : '—')
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label(__('banners.col.updated'))
                    ->since()
                    ->sortable()
                    ->alignEnd(),
            ])
            // Per-banner clicks + impressions over the last 30 days, computed as subqueries on the
            // list query (no N+1). CTR is derived from the two in the column above.
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withCount([
                'events as clicks_count' => static fn (Builder $q) => $q
                    ->where('kind', BannerEvent::KIND_CLICK)->where('created_at', '>=', now()->subDays(self::STATS_WINDOW_DAYS)),
                'events as impressions_count' => static fn (Builder $q) => $q
                    ->where('kind', BannerEvent::KIND_IMPRESSION)->where('created_at', '>=', now()->subDays(self::STATS_WINDOW_DAYS)),
            ]))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('banners.col.status'))
                    ->options(self::statusOptions()),
            ])
            ->emptyStateHeading(__('banners.empty'))
            ->emptyStateDescription(__('banners.empty_sub'))
            ->emptyStateIcon('heroicon-o-megaphone')
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'edit' => EditBanner::route('/{record}/edit'),
        ];
    }

    /** composition value → localized label. */
    public static function compositionOptions(): array
    {
        $out = [];
        foreach (Banner::COMPOSITIONS as $c) {
            $out[$c] = __('banners.composition_option.'.$c);
        }

        return $out;
    }

    /** status value → localized label (for the filter). */
    public static function statusOptions(): array
    {
        $out = [];
        foreach (Banner::STATUSES as $s) {
            $out[$s] = __('banners.status_option.'.$s);
        }

        return $out;
    }

    /** Banner status → a Filament badge colour slot (the theme tokens supply the colours). */
    private static function statusColor(string $status): string
    {
        return match ($status) {
            Banner::STATUS_ACTIVE => 'success',
            Banner::STATUS_PAUSED => 'warning',
            Banner::STATUS_ARCHIVED => 'gray',
            default => 'info', // draft
        };
    }
}
