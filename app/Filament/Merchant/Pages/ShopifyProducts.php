<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Products\ConfirmImportedProducts;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Domain\Shopify\Products\ShopifyProductSource;
use App\Domain\Shopify\Products\ShopifyProductSummary;
use App\Domain\Shopify\Products\StartShopifySync;
use App\Domain\Shopify\Products\StartSyncResult;
use App\Filament\Merchant\Concerns\ResolvesShopAccount;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Shopify products (Phase 3) — the merchant's import screen.
 *
 * Three actions, all explicit merchant acts:
 *  - IMPORT ALL: walk the whole catalog. Bounded by the platform SOFT CAP (default
 *    1,000; a super-admin may raise it) so one click cannot queue a 40k-product store
 *    into the bulk queue. Over the cap, the modal says so and the merchant picks instead.
 *  - IMPORT SELECTED: a live search against the Admin API; the merchant multi-selects.
 *  - CONFIRM ALL N IMPORTED: the friction-free bulk confirm. It still runs the SAME
 *    server-side ConfirmGate per product — imported fields are all high-confidence
 *    (the store's own record), so nothing blocks; nothing is force-confirmed.
 *
 * The page never talks to Shopify's write API and never persists a product itself: it
 * opens a ShopifySyncRun and the queued jobs do the work. The run's counters are the
 * live progress (a poll re-renders them).
 *
 * Tenant-safety: the account is the CURRENT SHOP TENANT's (ResolvesShopAccount), and
 * products/runs are read through their BelongsToAccount global scope. No manual account
 * filter, no withoutGlobalScopes().
 */
class ShopifyProducts extends Page
{
    use ResolvesShopAccount;

    // === CONSTANTS ===
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.merchant.pages.shopify-products';

    // The live progress poll — the run's counters are written by the queue.
    protected static ?string $pollingInterval = '5s';

    // How many recent runs the history strip shows.
    private const RUNS_LIMIT = 5;

    // i18n keys — never a literal in the page.
    private const TITLE = 'shopify.products.title';

    private const NAV_LABEL = 'shopify.products.nav';

    private const IMPORT_ALL_ACTION = 'shopify.products.import_all.action';

    private const IMPORT_ALL_HEADING = 'shopify.products.import_all.heading';

    private const IMPORT_ALL_SUB = 'shopify.products.import_all.sub';

    private const IMPORT_ALL_CAPPED = 'shopify.products.import_all.capped';

    private const IMPORT_ALL_CTA = 'shopify.products.import_all.cta';

    private const NOTIFY_REFUSED = 'shopify.products.notify.refused';

    private const IMPORT_SELECTED_ACTION = 'shopify.products.import_selected.action';

    private const IMPORT_SELECTED_FIELD = 'shopify.products.import_selected.field';

    private const IMPORT_SELECTED_HELP = 'shopify.products.import_selected.help';

    private const IMPORT_SELECTED_CTA = 'shopify.products.import_selected.cta';

    private const IMPORT_SELECTED_EMPTY = 'shopify.products.import_selected.empty';

    private const CONFIRM_ALL_ACTION = 'shopify.products.confirm_all.action';

    private const CONFIRM_ALL_HEADING = 'shopify.products.confirm_all.heading';

    private const CONFIRM_ALL_SUB = 'shopify.products.confirm_all.sub';

    private const CONFIRM_ALL_CTA = 'shopify.products.confirm_all.cta';

    private const NOTIFY_QUEUED = 'shopify.products.notify.queued';

    private const NOTIFY_QUEUED_BODY = 'shopify.products.notify.queued_body';

    private const NOTIFY_CONFIRMED = 'shopify.products.notify.confirmed';

    private const NOTIFY_BLOCKED = 'shopify.products.notify.blocked';

    private const NOTIFY_API_ERROR = 'shopify.products.notify.api_error';

    // A selection import bounded by selection_max: the picks it left out, said out loud.
    private const NOTIFY_TRUNCATED = 'shopify.products.notify.selection_truncated';

    private const NOTIFY_TRUNCATED_BODY = 'shopify.products.notify.selection_truncated_body';

    // The multi-select field name.
    private const FIELD_GIDS = 'gids';

    private const LOG_SEARCH_FAILED = 'shopify.products.search_failed';

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    /**
     * Only a Shopify-connected store gets the import item in its sidebar. A scripted
     * (platform=custom) shop has nothing to import, so the entry would be dead weight.
     * The page itself still renders its "connect first" empty state on a direct hit.
     */
    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Site && $tenant->platform === Site::PLATFORM_SHOPIFY;
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /** The screen only exists for a store that actually has Shopify connected. */
    public function isConnected(): bool
    {
        return $this->shopSite()->shopifyConnection?->isInstalled() === true;
    }

    /** The in-flight run (null when nothing is importing right now). */
    public function activeRun(): ?ShopifySyncRun
    {
        return app(StartShopifySync::class)->activeRun($this->shopSite());
    }

    /** @return Collection<int,ShopifySyncRun> */
    public function recentRuns(): Collection
    {
        return ShopifySyncRun::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->latest('id')
            ->limit(self::RUNS_LIMIT)
            ->get();
    }

    /**
     * The latest run, IF it was cut short by the page budget. The merchant must know that
     * their catalog is only partly imported — and that nothing was archived, so the
     * products the walk never reached are untouched, not lost.
     */
    public function truncatedRun(): ?ShopifySyncRun
    {
        $latest = $this->recentRuns()->first();

        return $latest?->isTruncated() === true ? $latest : null;
    }

    /** Imported products by state — the merchant's at-a-glance counters. */
    public function counters(): array
    {
        $base = fn (): Builder => Product::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->where('source', Product::SOURCE_SHOPIFY);

        return [
            'imported' => $base()->where('is_active', true)->count(),
            'draft' => $base()->where('is_active', true)->where('status', Product::STATUS_DRAFT)->count(),
            'confirmed' => $base()->where('is_active', true)->where('status', Product::STATUS_CONFIRMED)->count(),
            'archived' => $base()->where('is_active', false)->count(),
        ];
    }

    /** How many imported products are still awaiting the merchant's confirm. */
    public function pendingConfirmCount(): int
    {
        return app(ConfirmImportedProducts::class)->pendingCount($this->shopSite());
    }

    /**
     * Import the whole catalog (capped). The cap is a PLATFORM decision (config +
     * super-admin override) and it is ENFORCED IN StartShopifySync::catalog(), not here:
     * this modal only PREVIEWS it. Over the cap the domain refuses — nothing is opened,
     * nothing is queued — and the merchant is shown why and sent to the picker.
     */
    public function importAllAction(): Action
    {
        $sync = app(StartShopifySync::class);

        return Action::make('importAll')
            ->label(__(self::IMPORT_ALL_ACTION))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (): bool => $this->isConnected())
            ->requiresConfirmation()
            ->modalHeading(__(self::IMPORT_ALL_HEADING))
            ->modalDescription(function () use ($sync): string {
                try {
                    $size = $sync->catalogSize($this->shopSite());
                } catch (ShopifyApiException) {
                    return __(self::IMPORT_ALL_SUB, ['count' => '?', 'cap' => $sync->softCap()]);
                }

                return $sync->exceedsCap($size)
                    ? __(self::IMPORT_ALL_CAPPED, ['count' => $size, 'cap' => $sync->softCap()])
                    : __(self::IMPORT_ALL_SUB, ['count' => $size, 'cap' => $sync->softCap()]);
            })
            ->modalSubmitActionLabel(__(self::IMPORT_ALL_CTA))
            ->action(function () use ($sync): void {
                $result = $sync->catalog($this->shopSite());

                if ($result->refused()) {
                    $this->refusedNotification($result);

                    return;
                }

                $this->queuedNotification((int) $result->run?->getKey());
            });
    }

    /**
     * Import a hand-picked set. The search term rides to Shopify as a typed GraphQL
     * VARIABLE (never interpolated), so merchant input cannot become query syntax.
     */
    public function importSelectedAction(): Action
    {
        return Action::make('importSelected')
            ->label(__(self::IMPORT_SELECTED_ACTION))
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn (): bool => $this->isConnected())
            ->form([
                Select::make(self::FIELD_GIDS)
                    ->label(__(self::IMPORT_SELECTED_FIELD))
                    ->helperText(__(self::IMPORT_SELECTED_HELP))
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchProducts($search))
                    ->getOptionLabelsUsing(fn (array $values): array => array_combine($values, $values)),
            ])
            ->modalSubmitActionLabel(__(self::IMPORT_SELECTED_CTA))
            ->action(function (array $data): void {
                $gids = array_values(array_filter((array) ($data[self::FIELD_GIDS] ?? [])));

                if ($gids === []) {
                    Notification::make()->warning()->title(__(self::IMPORT_SELECTED_EMPTY))->send();

                    return;
                }

                $result = app(StartShopifySync::class)->selection($this->shopSite(), $gids);

                // The selection bound is REPORTED: picks past selection_max were not imported,
                // and the merchant hears it now rather than discovering it in the catalog.
                if ($result->wasTruncated()) {
                    Notification::make()
                        ->warning()
                        ->title(__(self::NOTIFY_TRUNCATED))
                        ->body(__(self::NOTIFY_TRUNCATED_BODY, [
                            'dropped' => $result->dropped,
                            'max' => (int) $result->cap,
                        ]))
                        ->send();

                    return;
                }

                $this->queuedNotification((int) $result->run?->getKey());
            });
    }

    /**
     * "Confirm all N imported" — the friction-free bulk confirm. Still an EXPLICIT
     * merchant act, and every product still passes the server-side ConfirmGate; a
     * product the gate blocks is skipped and reported, never forced.
     */
    public function confirmAllAction(): Action
    {
        return Action::make('confirmAll')
            ->label(fn (): string => __(self::CONFIRM_ALL_ACTION, ['count' => $this->pendingConfirmCount()]))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (): bool => $this->isConnected() && $this->pendingConfirmCount() > 0)
            ->requiresConfirmation()
            ->modalHeading(__(self::CONFIRM_ALL_HEADING))
            ->modalDescription(fn (): string => __(self::CONFIRM_ALL_SUB, ['count' => $this->pendingConfirmCount()]))
            ->modalSubmitActionLabel(__(self::CONFIRM_ALL_CTA))
            ->action(function (): void {
                $result = app(ConfirmImportedProducts::class)->confirmAll($this->shopSite());

                Notification::make()
                    ->success()
                    ->title(__(self::NOTIFY_CONFIRMED, ['count' => $result['confirmed']]))
                    ->body($result['blocked'] > 0 ? __(self::NOTIFY_BLOCKED, ['count' => $result['blocked']]) : null)
                    ->send();
            });
    }

    /**
     * The picker's live search: GID => label. A Shopify API failure degrades to an empty
     * result + a notification — never a 500 in the merchant's panel.
     *
     * @return array<string,string>
     */
    private function searchProducts(string $search): array
    {
        try {
            $results = app(ShopifyProductSource::class)->search($this->shopSite(), $search);
        } catch (ShopifyApiException $e) {
            Log::warning(self::LOG_SEARCH_FAILED, [
                'site_id' => (int) $this->shopSite()->getKey(),
                'code' => $e->errorCode,
            ]);

            Notification::make()->danger()->title(__(self::NOTIFY_API_ERROR))->send();

            return [];
        }

        $options = [];

        foreach ($results as $summary) {
            /** @var ShopifyProductSummary $summary */
            $options[$summary->gid] = $summary->title;
        }

        return $options;
    }

    private function queuedNotification(int $runId): void
    {
        Notification::make()
            ->success()
            ->title(__(self::NOTIFY_QUEUED))
            ->body(__(self::NOTIFY_QUEUED_BODY, ['run' => $runId]))
            ->send();
    }

    /**
     * The domain refused the import (over the cap / the catalog could not be measured).
     * Nothing was opened and nothing was queued — the merchant is told why, not 500'd.
     */
    private function refusedNotification(StartSyncResult $result): void
    {
        Notification::make()
            ->warning()
            ->title(__(self::NOTIFY_REFUSED))
            ->body(__((string) $result->reasonKey(), [
                'count' => number_format((int) $result->catalogSize),
                'cap' => number_format((int) $result->cap),
            ]))
            ->persistent()
            ->send();
    }

    /** @return array<int,Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->importAllAction(),
            $this->importSelectedAction(),
            $this->confirmAllAction(),
        ];
    }
}
