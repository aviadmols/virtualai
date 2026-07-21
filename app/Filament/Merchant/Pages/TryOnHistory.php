<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Generation\History\MerchantTryOnHistory;
use App\Domain\Generation\History\TryOnHistoryItem;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * WS2 — the per-shop "Try-on history": every try-on generation (the mechanism's
 * activations) for the CURRENT shop, newest first. Each row shows the result
 * thumbnail (signed URL, or a placeholder when purged/failed — never a broken
 * image), the status badge, the shopper (deep-linked to the lead card when there is
 * a lead), the variant/options, and the timestamp.
 *
 * Tenant-safety: the shop is the Filament tenant (Site::class); the history is read
 * through MerchantTryOnHistory::forSite() which runs inside the site's own bound
 * account (BelongsToAccount global scope) — so account A can never see account B's
 * generations. No manual where(account_id), no withoutGlobalScopes(); a forgotten
 * filter fails closed. The page holds only scalar state (site id, page) — never a
 * serialized model — and re-resolves on demand.
 */
class TryOnHistory extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    // Single-shop model: the SITES group is retired, so try-on history sits as a
    // top-level item right under the Overview (ungrouped).
    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.merchant.pages.try-on-history';

    // i18n keys (history.*).
    private const TITLE = 'history.title';

    private const NAV_LABEL = 'history.nav';

    /** The current shop's id (scalar — Livewire-safe; re-resolved through the scope). */
    public ?int $siteId = null;

    /** True when there is a bound shop (there always is under Site tenancy). */
    public bool $hasSite = false;

    /** How many pages are currently loaded (the "load more" accumulator). */
    public int $loadedPages = 1;

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup === null ? null : __(self::$navigationGroup);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /** Bind the current shop (the Filament tenant) — the only shop this page reads. */
    public function mount(): void
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Site) {
            $this->siteId = (int) $tenant->getKey();
            $this->hasSite = true;
        }
    }

    /** Load one more page of history (append; the view re-reads the full window). */
    public function loadMore(): void
    {
        $this->loadedPages++;
    }

    /** The bound shop (account-scoped), or null when none is bound. */
    public function site(): ?Site
    {
        return $this->siteId !== null
            ? Site::query()->find($this->siteId)
            : null;
    }

    protected function getViewData(): array
    {
        $site = $this->site();

        if ($site === null) {
            return [
                'items' => collect(),
                'hasMore' => false,
                'site' => null,
            ];
        }

        // One window covering every loaded page, so "load more" keeps a stable order.
        $perPage = MerchantTryOnHistory::defaultPerPage();
        $result = app(MerchantTryOnHistory::class)->forSite(
            site: $site,
            page: 1,
            perPage: $perPage * max(1, $this->loadedPages),
        );

        /** @var Collection<int,TryOnHistoryItem> $items */
        $items = $result['items'];

        return [
            'items' => $items,
            'hasMore' => $result['hasMore'],
            'site' => $site,
        ];
    }
}
