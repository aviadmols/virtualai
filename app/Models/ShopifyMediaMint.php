<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShopifyMediaMint — ONE Shopify media object we put into a merchant's live product gallery.
 *
 * APPEND-ONLY, LIKE THE CREDIT LEDGER. A row is written the instant productCreateMedia hands us an
 * id, and it is NEVER updated, nulled or deleted. It is the answer to one question, and it must be
 * able to answer it after any crash, retry, reclaim or undo:
 *
 *   "Which media objects did WE ever put into this merchant's store?"
 *
 * `product_assets.shopify_media_id` cannot answer it. That column is a mutable pointer to the
 * CURRENT media of an asset: undo nulls it, a Shopify-FAILED media clears it, and a reclaimed push
 * overwrites it. Two things were built on that amnesia and both were bugs —
 *
 *   - UNDO could only delete the LAST id an asset carried, so an orphaned media (minted by a
 *     worker that was reclaimed out from under it) stayed live in the storefront forever, and
 *     "restore my original images" quietly lied;
 *   - the SNAPSHOT excluded "our own image" by that column, so a media whose link had been dropped
 *     was captured as a merchant ORIGINAL and a later undo RE-UPLOADED our AI image into the live
 *     store.
 *
 * Both now read THIS table, which forgets nothing. Tenant-owned (BelongsToAccount) + site-scoped +
 * product-scoped; a media id is unique per account.
 */
class ShopifyMediaMint extends Model
{
    use BelongsToAccount;

    // === CONSTANTS ===
    // Append-only: there is a created_at and there is no updated_at, because there is no update.
    public const UPDATED_AT = null;

    // account_id is stamped by BelongsToAccount; nothing here is ever set from request input.
    protected $fillable = [
        'site_id',
        'product_id',
        'product_asset_id',
        'shopify_media_id',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'product_asset_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productAsset(): BelongsTo
    {
        return $this->belongsTo(ProductAsset::class);
    }

    /**
     * Record a media we just minted — called in the SAME BREATH as the productCreateMedia that
     * returned the id, BEFORE the asset's own (mutable) pointer is written and before anything
     * that can throw. A crash below this line therefore still leaves the store cleanable.
     *
     * firstOrCreate, not create: a resumed push that re-reports the same media id is the SAME
     * mint, not a second one (the unique index says so too).
     */
    public static function record(ProductAsset $asset, string $mediaId): self
    {
        /** @var self */
        return static::query()->firstOrCreate(
            ['shopify_media_id' => $mediaId],
            [
                'site_id' => (int) $asset->site_id,
                'product_id' => (int) $asset->product_id,
                'product_asset_id' => (int) $asset->getKey(),
            ],
        );
    }

    /**
     * EVERY media id we ever minted on this product — the undo sweep (what to take back out of the
     * store) and the snapshot exclusion (what is not a merchant original). It is never filtered by
     * a status or a link: a row here means "we put this in their store", and that stays true.
     *
     * @return array<int,string>
     */
    public static function mediaIdsForProduct(int $productId): array
    {
        return static::query()
            ->where('product_id', $productId)
            ->pluck('shopify_media_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }
}
