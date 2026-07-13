<?php

namespace App\Domain\Products;

use App\Domain\Scan\Map\MappedProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * PersistProduct — the ONE writer that turns a MappedProduct (from any rail: the PDP
 * scan or the Shopify Admin API) into a persisted Product + its variants.
 *
 * Three laws it encodes so neither rail can re-earn the scar:
 *
 *  1. REFRESH-CONFIRMED. A webhook / re-sync updates the DATA of a CONFIRMED product;
 *     it NEVER touches its status. Confirm is a merchant act (the no-auto-approve law)
 *     and a background refresh may not undo it, nor silently re-confirm a draft.
 *  2. UPSERT, NEVER REPLACE. Variants are matched by VariantKey (the Shopify GID, else
 *     the option map) and updated in place, so `generations.product_variant_id` — and
 *     every gallery row pointing at it — survives a refresh.
 *  3. ARCHIVE, NEVER DELETE. A variant/product absent from the incoming payload becomes
 *     is_active=false + archived_at: it stops being offered for NEW generations, while
 *     the paid history that references it stays intact. And the ORIGIN owns "is it still
 *     offered": a persist never re-activates a product the platform has unpublished.
 *
 * Tenant-safety: every read/write goes through the BelongsToAccount global scope (the
 * caller has already bound the tenant); account_id is auto-stamped, never taken from a
 * payload. No withoutGlobalScopes().
 */
final readonly class PersistProduct
{
    // === CONSTANTS ===
    // The variant columns a rail may write. account_id/product_id are never among them
    // (auto-stamped by the tenant scope + the relation).
    private const WRITABLE_VARIANT_COLUMNS = [
        'external_id',
        'options',
        'position',
        'price_minor',
        'image_url',
        'sku',
        'available',
        'confidence',
    ];

    /**
     * Persist (create or refresh) one product and reconcile its variants.
     * Wrapped in a transaction so a half-written import never lands.
     */
    public function persist(Site $site, MappedProduct $mapped, ProductOrigin $origin): PersistResult
    {
        return DB::transaction(function () use ($site, $mapped, $origin): PersistResult {
            $product = $this->existing($site, $origin);
            $created = $product === null;

            $product ??= new Product;

            // A CONFIRMED product keeps its status through any background refresh.
            $statusPreserved = ! $created && $product->isConfirmed();

            $product->fill($mapped->toProductAttributes());
            $product->fill($origin->toAttributes());
            $product->site_id = $site->getKey();
            $product->last_synced_at = now();

            if (! $statusPreserved) {
                // A fresh or re-scanned/re-synced product lands DRAFT — a scan/sync
                // NEVER auto-approves. (Only Product::confirm() goes live.)
                $product->status = Product::STATUS_DRAFT;
            }

            // THE PLATFORM'S OWN LIFECYCLE FACT, never a guess. The payload proves the
            // product still EXISTS — not that the store still OFFERS it. Re-activating on
            // every persist made an unpublished product FLAP: the status:active catalog walk
            // archives it, the next products/update webhook re-reads it and brings it back.
            // So an origin that says "not active on the platform" stays archived.
            if ($origin->platformActive) {
                $product->is_active = true;
                $product->archived_at = null;
            } else {
                $product->is_active = false;
                $product->archived_at ??= now();
            }

            $product->save();

            [$upserted, $archived] = $this->reconcileVariants($product, $mapped->variantRows);

            return new PersistResult(
                product: $product,
                created: $created,
                variantsUpserted: $upserted,
                variantsArchived: $archived,
                statusPreserved: $statusPreserved,
            );
        });
    }

    /**
     * The row this origin refers to, or null when it is new.
     *
     * Shopify: matched by (site, external_id) — the platform's own id. If none exists,
     * a previously SCANNED row for the same url is ADOPTED (its status, generations and
     * gallery survive; it is simply upgraded to the Shopify rail) instead of creating a
     * duplicate product for the same PDP.
     *
     * Scan: matched by (site, source_url_hash) and only in a re-scannable state — a
     * CONFIRMED scan row is never overwritten by a queued re-scan (the merchant's
     * corrections stand; a re-scan over a confirmed product is an explicit action).
     */
    private function existing(Site $site, ProductOrigin $origin): ?Product
    {
        if ($origin->isShopify() && $origin->externalId !== null) {
            $byExternal = Product::query()
                ->where('site_id', $site->getKey())
                ->where('external_id', $origin->externalId)
                ->first();

            if ($byExternal !== null) {
                return $byExternal;
            }

            return Product::query()
                ->where('site_id', $site->getKey())
                ->where('source_url_hash', $origin->sourceUrlHash())
                ->whereNull('external_id')
                ->first();
        }

        return Product::query()
            ->where('site_id', $site->getKey())
            ->where('source_url_hash', $origin->sourceUrlHash())
            ->whereIn('status', [Product::STATUS_DRAFT, Product::STATUS_FAILED])
            ->first();
    }

    /**
     * Upsert the incoming variant rows by VariantKey and ARCHIVE the ones the payload
     * no longer carries. Nothing is deleted, so no FK from a past generation is orphaned.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{0:int,1:int} [upserted, archived]
     */
    private function reconcileVariants(Product $product, array $rows): array
    {
        /** @var array<string,ProductVariant> $existing */
        $existing = $product->variants()
            ->get()
            ->keyBy(fn (ProductVariant $variant): string => VariantKey::forModel($variant))
            ->all();

        $seen = [];
        $upserted = 0;

        foreach ($rows as $position => $row) {
            $key = VariantKey::forRow($row);
            $seen[$key] = true;

            $attributes = array_intersect_key($row, array_flip(self::WRITABLE_VARIANT_COLUMNS));
            $attributes['position'] = (int) ($row['position'] ?? $position);
            $attributes['is_active'] = true;

            $variant = $existing[$key] ?? null;

            if ($variant === null) {
                $product->variants()->create($attributes);
                $upserted++;

                continue;
            }

            // Upsert in place: the id every past Generation references stays valid.
            $variant->fill($attributes);
            $variant->archived_at = null;
            $variant->save();
            $upserted++;
        }

        $archived = 0;

        foreach ($existing as $key => $variant) {
            if (isset($seen[$key]) || ! $variant->is_active) {
                continue;
            }

            $variant->archive();
            $archived++;
        }

        return [$upserted, $archived];
    }
}
