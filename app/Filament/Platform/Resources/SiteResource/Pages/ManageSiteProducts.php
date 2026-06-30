<?php

namespace App\Filament\Platform\Resources\SiteResource\Pages;

use App\Domain\Platform\PlatformProductQuery;
use App\Domain\Scan\Review\ConfirmScanAction;
use App\Domain\Scan\Review\ConfirmScanInput;
use App\Domain\Scan\Review\ScanConfirmBlockedException;
use App\Domain\Scan\Review\ScanReview;
use App\Domain\Scan\Review\ScanReviewRow;
use App\Filament\Platform\Resources\SiteResource;
use App\Models\Product;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * P3 — Platform per-site scan review/confirm (cross-account, super-admin only).
 *
 * The Super-Admin reviews a site's SCANNED products and confirms a DRAFT one WITHOUT a
 * bound tenant. Reads ride the audited PlatformProductQuery seam (the one sanctioned
 * withoutGlobalScope(AccountScope::class), guarded by PlatformGuard). The CONFIRM write
 * goes through ConfirmScanAction, which wraps Tenant::run($product->account_id, …) — a
 * tenant BINDING, never a scope bypass. There is NO inline withoutGlobalScopes() here.
 *
 * No blind auto-approve: a DRAFT product's extracted fields + variants are shown
 * READ-ONLY in a modal so the super-admin verifies before confirming. "Confirm as
 * scanned" marks every blocking review row reviewed (no field corrections, no selector
 * overrides) so ConfirmGate opens and the product transitions DRAFT → CONFIRMED. A scan
 * still blocked by something the gate cannot pass surfaces as a friendly notice, never a 500.
 */
class ManageSiteProducts extends Page implements HasTable
{
    use InteractsWithTable;

    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    protected static string $view = 'filament.platform.resources.site.products';

    // i18n keys (platform.sites.products.*).
    private const TITLE = 'platform.sites.products.title';
    private const COL_NAME = 'platform.sites.products.col.name';
    private const COL_STATUS = 'platform.sites.products.col.status';
    private const COL_CONFIDENCE = 'platform.sites.products.col.confidence';
    private const COL_VARIANTS = 'platform.sites.products.col.variants';
    private const COL_CREATED = 'platform.sites.products.col.created';
    private const STATUS_KEY_PREFIX = 'platform.sites.products.status.';
    private const REVIEW_HEADING = 'platform.sites.products.review.heading';
    private const REVIEW_SUB = 'platform.sites.products.review.sub';
    private const REVIEW_VIEW = 'filament.platform.resources.site.product-review';
    private const REVIEW_LABEL = 'platform.sites.products.review.label';
    private const CONFIRM_LABEL = 'platform.sites.products.confirm.label';
    private const CONFIRM_HEADING = 'platform.sites.products.confirm.heading';
    private const CONFIRM_SUB = 'platform.sites.products.confirm.sub';
    private const CONFIRM_SUBMIT = 'platform.sites.products.confirm.submit';
    private const CONFIRM_DONE = 'platform.sites.products.confirm.done';
    private const CONFIRM_BLOCKED = 'platform.sites.products.confirm.blocked';
    private const EMPTY_HEADING = 'platform.sites.products.empty';
    private const EMPTY_SUB = 'platform.sites.products.empty_sub';

    // status token → plain badge tone.
    private const STATUS_COLORS = [
        Product::STATUS_DRAFT => 'warning',
        Product::STATUS_CONFIRMED => 'success',
        Product::STATUS_FAILED => 'danger',
    ];

    /** The Site whose products we review (resolved through the audited Site seam). */
    public Site $site;

    /** Resolve the Site via the resource's audited cross-account query, super-admin only. */
    public function mount(int|string $record): void
    {
        $this->site = SiteResource::getEloquentQuery()->findOrFail($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE, ['site' => $this->site->name]);
    }

    /**
     * The site's scanned products via the AUDITED PlatformProductQuery seam (super-admin
     * cross-account read). Filament needs a same-model Builder for its table; this is the
     * one sanctioned bypass and is guarded by PlatformGuard.
     */
    protected function getTableQuery(): Builder
    {
        return PlatformProductQuery::forSite((int) $this->site->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('name')
                    ->label(__(self::COL_NAME))
                    ->weight('medium')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__(self::COL_STATUS))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUS_KEY_PREFIX.$state))
                    ->color(static fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('confidence')
                    ->label(__(self::COL_CONFIDENCE))
                    ->formatStateUsing(static fn (?float $state): string => $state === null ? '—' : round($state * 100).'%')
                    ->color('gray'),
                TextColumn::make('variants_count')
                    ->label(__(self::COL_VARIANTS))
                    ->state(static fn (Product $product): int => $product->variants->count())
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label(__(self::COL_CREATED))
                    ->since()
                    ->alignEnd(),
            ])
            ->actions([
                $this->reviewAction(),
                $this->confirmAction(),
            ])
            ->emptyStateHeading(__(self::EMPTY_HEADING))
            ->emptyStateDescription(__(self::EMPTY_SUB))
            ->emptyStateIcon('heroicon-o-cube')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * "Review" — a READ-ONLY modal of the product's extracted fields + variants, built
     * from the same ScanReview read model the merchant A4 form binds to (no scan logic in
     * the view). Available on every product so a confirmed/failed one can also be inspected.
     */
    private function reviewAction(): Action
    {
        return Action::make('review')
            ->label(__(self::REVIEW_LABEL))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(__(self::REVIEW_HEADING))
            ->modalDescription(__(self::REVIEW_SUB))
            ->modalSubmitAction(false)
            ->modalContent(fn (Product $record): View => view(self::REVIEW_VIEW, [
                'product' => $record,
                'review' => ScanReview::fromProduct($record),
            ]));
    }

    /**
     * "Confirm" — DRAFT only. Shows the same read-only review for verification, then on
     * submit confirms via ConfirmScanAction with EVERY blocking row marked reviewed (a
     * super-admin "confirm as scanned": no field corrections, no selector overrides). The
     * action binds the product's own account (Tenant::run) and runs the guarded
     * draft → confirmed transition. A still-blocked gate surfaces a friendly notice.
     */
    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(__(self::CONFIRM_LABEL))
            ->icon('heroicon-o-check-badge')
            ->color('primary')
            ->visible(static fn (Product $record): bool => $record->isDraft())
            ->requiresConfirmation()
            ->modalHeading(__(self::CONFIRM_HEADING))
            ->modalDescription(__(self::CONFIRM_SUB))
            ->modalSubmitActionLabel(__(self::CONFIRM_SUBMIT))
            ->modalContent(fn (Product $record): View => view(self::REVIEW_VIEW, [
                'product' => $record,
                'review' => ScanReview::fromProduct($record),
            ]))
            ->action(function (Product $record): void {
                $this->confirmProduct($record);
            });
    }

    /**
     * Confirm a draft product "as scanned": acknowledge every blocking review row so the
     * ConfirmGate opens, with no corrections/overrides. Surfaces ScanConfirmBlockedException
     * as a friendly warning notice (never a 500).
     */
    private function confirmProduct(Product $product): void
    {
        // Reload through the audited seam so we have variants + freshness without a tenant.
        $fresh = PlatformProductQuery::findWithVariants((int) $product->getKey());

        if ($fresh === null) {
            return;
        }

        $reviewedKeys = $this->allBlockingKeys($fresh);

        $input = new ConfirmScanInput(
            fieldValues: [],
            selectors: [],
            variants: [],
            reviewedKeys: $reviewedKeys,
        );

        try {
            app(ConfirmScanAction::class)->confirm($fresh, $input);
        } catch (ScanConfirmBlockedException $e) {
            Notification::make()
                ->warning()
                ->title(__(self::CONFIRM_BLOCKED, ['count' => count($e->blockingKeys)]))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__(self::CONFIRM_DONE))
            ->send();
    }

    /**
     * Every blocking review row's gate identifier ("field:price"), so the super-admin
     * "confirm as scanned" acknowledges all of them and the gate opens.
     *
     * @return array<int,string>
     */
    private function allBlockingKeys(Product $product): array
    {
        $keys = [];

        foreach (ScanReview::fromProduct($product)->rows() as $row) {
            if ($row instanceof ScanReviewRow && $row->blocksConfirm()) {
                $keys[] = $row->kind.':'.$row->key;
            }
        }

        return $keys;
    }
}
