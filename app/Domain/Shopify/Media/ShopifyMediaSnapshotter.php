<?php

namespace App\Domain\Shopify\Media;

use App\Domain\Media\MediaStorage;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Models\Product;
use App\Models\ShopifyConnection;
use App\Models\ShopifyMediaMint;
use App\Models\ShopifyMediaSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShopifyMediaSnapshotter — takes OUR OWN copy of a product's original Shopify gallery, and it
 * is the pre-flight every DESTRUCTIVE push must pass.
 *
 * THE SCAR THIS CLASS EXISTS TO PREVENT. Replace and reorder change a LIVE storefront. Shopify
 * drops an image's CDN bytes once its media object is deleted — so an undo that only remembered
 * media IDs and positions could restore an ORDER but never an IMAGE. We therefore download the
 * original BYTES and store them ourselves before anything is touched. If that cannot be done,
 * the push does not run: ensure() THROWS, and the caller fails closed. A degraded snapshot is
 * not "better than nothing"; it is an undo button that lies.
 *
 * THREE PROPERTIES ARE LOCKED HERE, and each one was a real way to lose a merchant's image:
 *
 *  1. IT HOLDS THE MERCHANT'S TRUE ORIGINALS — never OUR OWN media. An append is not destructive
 *     and takes no snapshot, so the FIRST destructive push can find a gallery that already
 *     contains images we pushed. Snapshotting those as "originals" made undo re-upload OUR AI
 *     image into the live storefront on a second run, where it would stay forever. Every media we
 *     EVER minted on this product is therefore EXCLUDED — read from the append-only mint ledger
 *     (shopify_media_mints), never from the mutable shopify_media_id pointer that undo, a dead
 *     media and a reclaim all deliberately drop — and the original positions are rebased over what
 *     remains.
 *
 *  2. THE BYTES ARE VERIFIED, NOT ATTEMPTED. Every disk is `throw => false`, so a failed write
 *     returns FALSE and used to hand back a path pointing at nothing — a snapshot stamped
 *     CAPTURED with no bytes behind it, which then licensed the deletion of an original we did
 *     not hold. MediaStorage now throws on an unverified write, and the whole capture is READ
 *     BACK before the snapshot may transition to CAPTURED.
 *
 *  3. A THROTTLE IS NOT A CAPTURE FAILURE. A rate-limited gallery read is re-thrown as the typed
 *     ShopifyApiException so the push job PARKS and comes back — it does not burn the snapshot.
 *
 * Idempotent by construction: ONE snapshot per product (unique (account_id, product_id)), taken
 * once — the original state is the original state. A second destructive push finds the existing
 * CAPTURED row and touches nothing. A previously FAILED capture is retried.
 *
 * The snapshot is NEVER deleted by a retention purge while the product exists: its objects live
 * under the site's media prefix, which is only purged when the site (and so the product) is gone.
 */
final class ShopifyMediaSnapshotter
{
    // === CONSTANTS ===
    private const CFG_MAX_BYTES = 'shopify.media.snapshot_max_bytes';

    private const DEFAULT_MAX_BYTES = 20_971_520; // 20 MiB

    private const DOWNLOAD_TIMEOUT_SECONDS = 30;

    private const DEFAULT_MIME = 'image/jpeg';

    private const HEADER_CONTENT_TYPE = 'Content-Type';

    private const LOG_CAPTURED = 'shopify.media.snapshot_captured';

    private const LOG_FAILED = 'shopify.media.snapshot_failed';

    private const MSG_DOWNLOAD_FAILED = 'the original image %s could not be downloaded (HTTP %d)';

    private const MSG_TOO_LARGE = 'the original image %s is larger than the %d-byte snapshot ceiling';

    private const MSG_UNVERIFIED = 'the backup of the original image %s is missing or empty on readback';

    public function __construct(
        private readonly ShopifyMediaClient $client,
        private readonly MediaStorage $media,
    ) {}

    /**
     * Return this product's CAPTURED original-gallery snapshot, taking it if it does not exist.
     *
     * @throws MediaSnapshotException when the originals cannot be copied — the caller MUST then
     *                                refuse the destructive push (fail closed).
     * @throws ShopifyApiException when the store THROTTLED us — the caller PARKS and retries;
     *                             a park is not a failure and must not burn the snapshot.
     */
    public function ensure(Product $product, ShopifyConnection $connection): ShopifyMediaSnapshot
    {
        $snapshot = $this->existing($product);

        if ($snapshot?->isCaptured() === true) {
            return $snapshot;
        }

        $snapshot ??= ShopifyMediaSnapshot::query()->create([
            'site_id' => (int) $product->site_id,
            'product_id' => (int) $product->getKey(),
            'external_id' => (string) $product->external_id,
            'status' => ShopifyMediaSnapshot::STATUS_CAPTURING,
        ]);

        if ($snapshot->status === ShopifyMediaSnapshot::STATUS_FAILED) {
            $snapshot->transitionTo(ShopifyMediaSnapshot::STATUS_CAPTURING);
        }

        // Objects written by THIS attempt. A capture that dies on original 4 must not leave 1-3
        // orphaned on the disk with no row pointing at them.
        $written = [];

        try {
            $entries = $this->capture($product, $connection, $written);
            $this->assertVerified($entries);
        } catch (ShopifyApiException $e) {
            // A THROTTLE is not a capture failure: the store is busy, not broken. Leave the
            // snapshot `capturing` and let the job park — it will come back and finish it.
            $this->discard($written);

            throw $e;
        } catch (Throwable $e) {
            $this->discard($written);
            $this->markFailed($snapshot, $product, $e->getMessage());

            throw MediaSnapshotException::captureFailed((int) $product->getKey(), $e->getMessage());
        }

        $snapshot->forceFill(['media' => $entries, 'failure_message' => null])->save();
        $snapshot->transitionTo(ShopifyMediaSnapshot::STATUS_CAPTURED, ['originals' => count($entries)]);

        Log::info(self::LOG_CAPTURED, [
            'account_id' => (int) $product->account_id,
            'site_id' => (int) $product->site_id,
            'product_id' => (int) $product->getKey(),
            'originals' => count($entries),
        ]);

        return $snapshot;
    }

    /** This product's snapshot, if one was ever started (tenant-scoped; fail closed). */
    public function existing(Product $product): ?ShopifyMediaSnapshot
    {
        return ShopifyMediaSnapshot::query()
            ->where('product_id', $product->getKey())
            ->first();
    }

    /**
     * Read the live gallery and copy every ORIGINAL image's BYTES onto our own disk.
     *
     * "Original" means the MERCHANT'S — a media WE put in the store (an earlier append) is skipped
     * outright, or undo would treat our own AI image as an original and re-inject it into the live
     * storefront. Positions are rebased over the originals that remain, so the restored order is
     * the order the merchant actually started with.
     *
     * A non-image entry (video / 3D model) has no downloadable image url: it is recorded with a
     * null path, and a REPLACE that targets it is refused later (we could not put it back). It is
     * never a reason to fail the whole snapshot — a reorder does not destroy it.
     *
     * @param  array<int,string>  $written  out-param: the objects this attempt wrote (for cleanup)
     * @return array<int,array<string,mixed>>
     */
    private function capture(Product $product, ShopifyConnection $connection, array &$written): array
    {
        $ours = $this->ourMediaIds($product);
        $entries = [];
        $position = MediaPlacement::FIRST_POSITION;

        foreach ($this->client->gallery($connection, (string) $product->external_id) as $item) {
            if (in_array($item->id, $ours, true)) {
                continue; // OUR image, not the merchant's original — it must never enter a snapshot
            }

            $entry = [
                ShopifyMediaSnapshot::ENTRY_MEDIA_ID => $item->id,
                ShopifyMediaSnapshot::ENTRY_ALT => $item->alt,
                ShopifyMediaSnapshot::ENTRY_POSITION => $position++,
                ShopifyMediaSnapshot::ENTRY_SOURCE_URL => $item->imageUrl,
                ShopifyMediaSnapshot::ENTRY_PATH => null,
                ShopifyMediaSnapshot::ENTRY_MIME => null,
                ShopifyMediaSnapshot::ENTRY_BYTES => 0,
            ];

            if ($item->imageUrl !== null) {
                [$bytes, $mime] = $this->download($item->id, $item->imageUrl);

                // A rejected / unverified write THROWS (MediaWriteException) — it can no longer
                // hand back a path with nothing behind it.
                $stored = $this->media->storeShopifySnapshot(
                    (int) $product->account_id,
                    (int) $product->site_id,
                    (int) $product->getKey(),
                    $bytes,
                    $mime,
                );

                $written[] = $stored->path;

                $entry[ShopifyMediaSnapshot::ENTRY_PATH] = $stored->path;
                $entry[ShopifyMediaSnapshot::ENTRY_MIME] = $stored->mimeType;
                $entry[ShopifyMediaSnapshot::ENTRY_BYTES] = $stored->byteSize;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * THE LAST GATE BEFORE `captured`. Every original we claim to hold is READ BACK off the disk:
     * the object exists and it is not empty. A snapshot that cannot pass this may not be stamped
     * CAPTURED, because CAPTURED is what licenses the deletion of a live original.
     *
     * @param  array<int,array<string,mixed>>  $entries
     */
    private function assertVerified(array $entries): void
    {
        foreach ($entries as $entry) {
            $path = $entry[ShopifyMediaSnapshot::ENTRY_PATH] ?? null;

            if ($path === null) {
                continue; // a video / 3D model: no bytes to hold, and a replace of it is refused
            }

            if (! is_string($path) || ! $this->media->isReadable($path)) {
                throw new \RuntimeException(sprintf(
                    self::MSG_UNVERIFIED,
                    (string) ($entry[ShopifyMediaSnapshot::ENTRY_MEDIA_ID] ?? '?'),
                ));
            }
        }
    }

    /**
     * The media ids of THIS product that WE put in the store. They are not originals and may
     * never be snapshotted as such.
     *
     * IT READS THE MINT LEDGER, NOT `product_assets.shopify_media_id`. That column is a MUTABLE
     * POINTER: undo nulls it, a Shopify-FAILED media clears it, a reclaimed push overwrites it. Any
     * media of ours whose link had been dropped was therefore invisible to this exclusion — and a
     * later snapshot captured OUR AI image as a merchant "original", which a later undo then
     * re-uploaded into the live storefront, where it stayed forever. shopify_media_mints is
     * append-only and never nulled: if we ever put it in their store, it is not an original.
     *
     * @return array<int,string>
     */
    private function ourMediaIds(Product $product): array
    {
        return ShopifyMediaMint::mediaIdsForProduct((int) $product->getKey());
    }

    /**
     * Download ONE original from Shopify's CDN, bounded by the byte ceiling so a hostile or
     * absurd object can never OOM the worker. Any failure aborts the whole capture (fail closed).
     *
     * @return array{0: string, 1: string}
     */
    private function download(string $mediaId, string $url): array
    {
        $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(self::MSG_DOWNLOAD_FAILED, $mediaId, $response->status()));
        }

        $bytes = $response->body();

        if (strlen($bytes) > $this->maxBytes()) {
            throw new \RuntimeException(sprintf(self::MSG_TOO_LARGE, $mediaId, $this->maxBytes()));
        }

        $mime = (string) ($response->header(self::HEADER_CONTENT_TYPE) ?: self::DEFAULT_MIME);

        return [$bytes, trim(explode(';', $mime)[0])];
    }

    /** Drop the objects a failed capture attempt wrote — no orphans with no row pointing at them. */
    private function discard(array $written): void
    {
        foreach ($written as $path) {
            $this->media->delete($path);
        }
    }

    private function markFailed(ShopifyMediaSnapshot $snapshot, Product $product, string $reason): void
    {
        Log::warning(self::LOG_FAILED, [
            'account_id' => (int) $product->account_id,
            'site_id' => (int) $product->site_id,
            'product_id' => (int) $product->getKey(),
            'reason' => $reason,
        ]);

        $snapshot->forceFill(['failure_message' => $reason])->save();
        $snapshot->transitionTo(ShopifyMediaSnapshot::STATUS_FAILED, ['reason' => $reason]);
    }

    private function maxBytes(): int
    {
        return max(1, (int) (config(self::CFG_MAX_BYTES) ?? self::DEFAULT_MAX_BYTES));
    }
}
