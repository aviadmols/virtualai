<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Gallery\GalleryItem;
use App\Domain\Gallery\MerchantGalleryQuery;
use App\Models\Site;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * M8 / A12 — the per-site try-on gallery. Renders the wall of SUCCEEDED try-ons for
 * one site via MerchantGalleryQuery::forSite() — a list of immutable GalleryItem DTOs
 * (newest first), each with a short-lived signed thumbnail or a `purged` flag. A
 * purged tile shows a placeholder, never a broken image (the query degrades a
 * missing/unreachable thumbnail to purged gracefully, so no 500).
 *
 * Tenant-safety: the site is resolved through the BelongsToAccount global scope (the
 * panel is account-bound), so a foreign site 404s — no manual where(account_id), no
 * withoutGlobalScopes(). The query itself runs inside the site's own bound tenant. The
 * page picks the account's first site by default; a ?site={id} deep-link (from the site
 * hub) selects another of the merchant's own sites.
 */
class Gallery extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.merchant.pages.gallery';

    // i18n keys.
    private const TITLE = 'settings.gallery.title';
    private const NAV_LABEL = 'settings.gallery.nav';

    /** The resolved site id (scalar — Livewire-safe; the model re-resolves on demand
        through the account-scoped global scope, never a serialized model prop). */
    public ?int $siteId = null;

    /** True when the account has at least one site (else the no-site empty state). */
    public bool $hasSite = false;

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /**
     * Resolve the site to show: the ?site={id} deep-link if present + owned, else the
     * account's first site. Both reads go through the account-scoped global scope.
     */
    public function mount(): void
    {
        $site = request()->query('site');

        $resolved = $site !== null
            ? Site::query()->find($site)
            : Site::query()->orderBy('id')->first();

        if ($resolved !== null) {
            $this->siteId = (int) $resolved->getKey();
            $this->hasSite = true;
        }
    }

    /** The bound site (account-scoped), or null when the account has none. */
    public function site(): ?Site
    {
        return $this->siteId !== null
            ? Site::query()->find($this->siteId)
            : null;
    }

    /**
     * The gallery items for the bound site (succeeded only, newest first). Returns an
     * empty collection when there is no site; the view renders the empty state.
     *
     * @return Collection<int,GalleryItem>
     */
    public function items(): Collection
    {
        $site = $this->site();

        if ($site === null) {
            return collect();
        }

        return app(MerchantGalleryQuery::class)->forSite($site);
    }

    protected function getViewData(): array
    {
        return [
            'items' => $this->items(),
            'site' => $this->site(),
        ];
    }
}
