<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Products\PersistProduct;
use App\Domain\Products\PersistResult;
use App\Domain\Scan\Map\MappedProduct;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Models\Site;

/**
 * ShopifyProductImporter — the ONE place a Shopify product becomes a Tray On product.
 *
 * Fetch (ShopifyProductSource) -> persist (the shared PersistProduct writer) -> count on
 * the sync run -> write the timeline event. Used identically by the catalog walk, the
 * single-product sync, and the products/update webhook, so "how an imported product is
 * written" cannot drift between the three.
 *
 * Two lifecycle rules it owns:
 *  - a product Shopify no longer returns is ARCHIVED (is_active=false + archived_at),
 *    never deleted — generations and gallery rows still point at it;
 *  - archiveStale() (the "it vanished from the catalog" sweep) runs ONLY after a FULL
 *    catalog walk. A selection run imports a subset, so archiving "everything it did not
 *    see" would wipe the merchant's catalog — the mode guard is a hard release blocker.
 *
 * Tenant-safety: every read/write runs under the caller's already-bound tenant, through
 * the BelongsToAccount global scope. No withoutGlobalScopes(), no ambient account.
 */
final readonly class ShopifyProductImporter
{
    // === CONSTANTS ===
    private const DETAIL_GID = 'external_id';

    private const DETAIL_RUN = 'sync_run_id';

    private const DETAIL_SOURCE = 'source';

    public function __construct(
        private ShopifyProductSource $source,
        private PersistProduct $persist,
        private ActivityRecorder $activity,
    ) {}

    /**
     * Import (or refresh) one product by GID.
     *
     * @throws ShopifyProductNotFoundException when Shopify no longer has the product
     * @throws ShopifyApiException on transport / throttle / auth
     */
    public function importOne(Site $site, string $gid, ?ShopifySyncRun $run = null): PersistResult
    {
        [$mapped, $ref] = $this->source->fetch($site, $gid);

        return $this->importMapped($site, $mapped, $ref, $run);
    }

    /** Persist an already-fetched page entry (the catalog walk's hot path). */
    public function importMapped(Site $site, MappedProduct $mapped, ShopifyProductRef $ref, ?ShopifySyncRun $run = null): PersistResult
    {
        $result = $this->persist->persist($site, $mapped, $ref->toOrigin());

        $this->count($run, $result->created ? ShopifySyncRun::COUNTER_IMPORTED : ShopifySyncRun::COUNTER_UPDATED);

        $this->activity->record(
            kind: $result->created
                ? ActivityEvent::KIND_SHOPIFY_PRODUCT_IMPORTED
                : ActivityEvent::KIND_SHOPIFY_PRODUCT_UPDATED,
            subject: $result->product,
            details: [
                self::DETAIL_GID => $ref->gid,
                self::DETAIL_RUN => $run?->getKey(),
                'variants_upserted' => $result->variantsUpserted,
                'variants_archived' => $result->variantsArchived,
                'status_preserved' => $result->statusPreserved,
            ],
            siteId: (int) $site->getKey(),
            actor: ActivityEvent::ACTOR_SYSTEM,
        );

        return $result;
    }

    /**
     * Archive the local product for a GID Shopify no longer has (the products/delete
     * webhook, and a 404 mid-sync). Never a delete: the try-ons the merchant paid for
     * reference this row. Idempotent — a replayed delete changes nothing.
     */
    public function archiveByGid(Site $site, string $gid, ?ShopifySyncRun $run = null): ?Product
    {
        $product = Product::query()
            ->where('site_id', $site->getKey())
            ->where('external_id', $gid)
            ->first();

        if ($product === null || ! $product->is_active) {
            return $product;
        }

        $product->archive();

        $this->count($run, ShopifySyncRun::COUNTER_ARCHIVED);

        $this->activity->record(
            kind: ActivityEvent::KIND_SHOPIFY_PRODUCT_ARCHIVED,
            subject: $product,
            details: [self::DETAIL_GID => $gid, self::DETAIL_RUN => $run?->getKey(), self::DETAIL_SOURCE => Product::SOURCE_SHOPIFY],
            siteId: (int) $site->getKey(),
            actor: ActivityEvent::ACTOR_SYSTEM,
        );

        return $product;
    }

    /**
     * After a FULL catalog walk: archive every Shopify product of this site the walk did
     * not touch (its last_synced_at predates the run) — it is gone from the store.
     *
     * THE MODE GUARD IS LOAD-BEARING. A `selection` run imports an explicit subset; if
     * this ran for it, every product the merchant did NOT select would be archived — the
     * whole catalog wiped by a two-product import. Only MODE_CATALOG may sweep.
     * Scanned products are never touched: Shopify is not authoritative for them.
     */
    public function archiveStale(Site $site, ShopifySyncRun $run): int
    {
        if ($run->mode !== ShopifySyncRun::MODE_CATALOG || $run->started_at === null) {
            return 0;
        }

        $stale = Product::query()
            ->where('site_id', $site->getKey())
            ->where('source', Product::SOURCE_SHOPIFY)
            ->where('is_active', true)
            ->where(function ($query) use ($run): void {
                $query->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', $run->started_at);
            })
            ->get();

        foreach ($stale as $product) {
            $product->archive();

            $this->count($run, ShopifySyncRun::COUNTER_ARCHIVED);

            $this->activity->record(
                kind: ActivityEvent::KIND_SHOPIFY_PRODUCT_ARCHIVED,
                subject: $product,
                details: [
                    self::DETAIL_GID => $product->external_id,
                    self::DETAIL_RUN => $run->getKey(),
                    'reason' => 'absent_from_catalog',
                ],
                siteId: (int) $site->getKey(),
                actor: ActivityEvent::ACTOR_SYSTEM,
            );
        }

        return $stale->count();
    }

    /** Bump one counter on the run (a run-less import — e.g. a webhook — counts nothing). */
    private function count(?ShopifySyncRun $run, string $counter): void
    {
        $run?->increment($counter);
    }
}
