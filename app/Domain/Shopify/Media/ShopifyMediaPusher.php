<?php

namespace App\Domain\Shopify\Media;

use App\Domain\Media\MediaStorage;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ShopifyConnection;
use App\Models\ShopifyMediaMint;
use App\Models\ShopifyMediaSnapshot;
use App\Models\Site;
use RuntimeException;

/**
 * ShopifyMediaPusher — moves ONE approved image into a LIVE product gallery, in the only order
 * that leaves the storefront valid at every intermediate step.
 *
 * The sequence, and why each step is where it is:
 *
 *   1. SNAPSHOT (only for a DESTRUCTIVE placement — replace / position N).
 *      Our own byte-level copy of the original gallery, taken BEFORE anything is touched. If it
 *      cannot be taken, the push is REFUSED and nothing is mutated. Shopify drops the bytes when
 *      a media is deleted, so without this the undo button would be a lie. FAIL CLOSED.
 *      And a snapshot that EXISTS is not a snapshot that HOLDS: before a destructive push may
 *      run, every original it claims is READ BACK off the disk (it exists, it is not empty), and
 *      a REPLACE additionally proves the specific image it is about to DELETE can be handed back.
 *      A path string is not bytes.
 *
 *   2. STAGED UPLOAD -> productCreateMedia.
 *      The bytes leave OUR private bucket directly into Shopify's staging target — the bucket is
 *      never made public. The media id is persisted THE MOMENT Shopify hands it back: it is the
 *      anti-duplicate anchor (the twin of provider_request_id on the generation rail). A retry or
 *      a re-push of an asset that already carries one NEVER uploads again — it resumes.
 *
 *   3. AWAIT READY.
 *      Shopify processes media asynchronously (UPLOADED -> PROCESSING -> READY|FAILED). Nothing
 *      destructive may run on the strength of an UPLOADED media.
 *
 *   4. PLACEMENT.
 *      append   -> already done by step 2 (createMedia appends).
 *      position -> reorder the new media into the slot.
 *      replace  -> reorder the new media into the replaced image's slot, and ONLY THEN delete the
 *                  replaced media. NEVER delete before the replacement is confirmed READY: at
 *                  every intermediate state the gallery holds a valid, displayable image.
 *                  And the bytes are RE-PROVED as the last statement before the delete — the
 *                  gate in step 1 is a minute old by then, and a minute is enough for a bucket
 *                  lifecycle rule to make it a lie (deleteReplaced).
 *
 * A push is FREE. It reserves nothing, charges nothing and writes no ledger row: the AI already
 * ran and was paid for when the asset succeeded. Nothing in this class may ever touch credits.
 */
final class ShopifyMediaPusher
{
    // === CONSTANTS ===
    private const CFG_ALT_TEMPLATE = 'shopify.media.alt_template';

    private const DEFAULT_ALT_TEMPLATE = '{product_name} — {operation}';

    // Alt-text placeholders. Substituted with strtr() — NEVER Blade::render() (RCE prevention:
    // this template is admin-editable text).
    private const VAR_PRODUCT_NAME = '{product_name}';

    private const VAR_OPERATION = '{operation}';

    private const OPERATION_LABEL_KEY = 'product_images.operation.';

    private const FILENAME_TEMPLATE = 'trayon-%s-%d.%s';

    // The filename slug a RE-UPLOADED original carries back into the store.
    private const FILENAME_ORIGINAL = 'original';

    private const DEFAULT_MIME = 'image/png';

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const DEFAULT_EXTENSION = 'png';

    private const MSG_NO_CONNECTION = 'Site #%d has no installed Shopify connection; the image cannot be pushed.';

    private const MSG_NOT_SHOPIFY = 'Product #%d is not a Shopify product (no external id); the image cannot be pushed.';

    private const MSG_NO_BYTES = 'The generated image of asset #%d is not on the media disk; the push was refused.';

    public function __construct(
        private readonly ShopifyMediaClient $client,
        private readonly ShopifyMediaSnapshotter $snapshotter,
        private readonly MediaStorage $media,
    ) {}

    /**
     * Push ONE approved asset into the product's gallery at the chosen placement. Returns the
     * Shopify media id. Every failure is typed (ShopifyMediaException / MediaSnapshotException /
     * ShopifyApiException / PushClaimLostException) — the job turns it into push_failed + the
     * verbatim message (a lost claim stands down instead).
     *
     * $claimId is the lease the calling job holds on the asset; it is RE-PROVED immediately
     * before the mint (createMedia), so a worker evicted by a reclaim cannot mint a second media.
     */
    public function push(ProductAsset $asset, Product $product, Site $site, MediaPlacement $placement, string $claimId): string
    {
        $connection = $this->connection($site);
        $productGid = $this->productGid($product);
        $snapshot = null;

        // --- 1. THE SNAPSHOT GATE. A destructive push without a RESTORABLE original is refused. ---
        if ($placement->isDestructive()) {
            $snapshot = $this->snapshotter->ensure($product, $connection);

            // Every original this snapshot claims must really be on our disk, with bytes in it.
            $this->assertSnapshotRestorable($snapshot);

            if ($placement->isReplace()) {
                // And the ONE image we are about to delete from a live storefront must be an
                // original we can hand back — byte for byte.
                $this->assertMediaRestorable($snapshot, (string) $placement->replaceMediaId);
            }
        }

        // --- 2. CREATE (or RESUME — an asset that already has a media id never uploads again) ---
        $mediaId = $asset->hasShopifyMedia()
            ? (string) $asset->shopify_media_id
            : $this->createMedia($asset, $product, $connection, $productGid, $claimId);

        // --- 3. THE READY GATE. Nothing below this line may run on an unprocessed media. ---
        $this->awaitReady($asset, $connection, $productGid, $mediaId);

        // --- 4. PLACEMENT. The snapshot travels WITH it: the proof taken in step 1 is a MINUTE
        //        old by now (a staged upload + a create + a 20 x 3s poll), and the delete is the
        //        one irreversible act in the system. It is re-proved down there, not up here. ---
        $this->place($connection, $productGid, $mediaId, $placement, $snapshot);

        return $mediaId;
    }

    /**
     * Restore a product's ORIGINAL gallery from the snapshot: re-upload every original Shopify no
     * longer has, put them back in their original order (so position 1 — the featured image — is
     * the one the merchant started with), and only THEN remove the images we added.
     *
     * The order is deliberate and non-negotiable: an original is never deleted, and OUR media is
     * only removed once every original is present and READY. Undo can therefore never destroy
     * anything unrecoverable, and re-running it is a no-op.
     *
     * IT IS IDEMPOTENT UNDER A CRASH, not just under a double-click. Every re-uploaded original's
     * new media id is persisted onto the snapshot IN THE SAME BREATH as the createMedia call that
     * minted it — before the READY poll, before the next original, before anything that can throw.
     * A worker that dies mid-restore (an exhausted poll budget, a throttle, an OOM) therefore
     * resumes; it does not re-upload the same original again and leave the merchant a gallery that
     * grows a DUPLICATE on every retry.
     *
     * WHAT LEAVES THE STORE IS DECIDED BY THE MINT LEDGER, NOT BY AN ASSET'S POINTER. $ourMediaIds
     * comes from shopify_media_mints — append-only, never nulled — so an ORPHAN (a media minted by
     * a push that later cleared or overwrote its own shopify_media_id: a Shopify-FAILED media, a
     * reclaimed worker, an undone push) is still taken back out of the merchant's live storefront.
     * The asset column forgets; the mint ledger does not.
     *
     * @param  array<int,string>  $ourMediaIds  every media we EVER minted on this product
     * @return array<int,string> the media ids removed from the store
     */
    public function restore(ShopifyMediaSnapshot $snapshot, Product $product, Site $site, array $ourMediaIds): array
    {
        $connection = $this->connection($site);
        $productGid = $this->productGid($product);

        if (! $snapshot->isCaptured()) {
            throw MediaSnapshotException::notCaptured((int) $product->getKey());
        }

        $live = $this->byId($this->client->gallery($connection, $productGid));
        $order = [];

        // (a) Every original must EXIST and be READY first. A missing one is re-uploaded from our
        //     own bytes, and its new id is persisted the instant Shopify hands it back.
        foreach ($snapshot->entries() as $entry) {
            $liveOriginal = $this->liveOriginal($entry, $live);

            if ($liveOriginal !== null) {
                $liveId = $liveOriginal->id;

                if (! $liveOriginal->isReady()) {
                    // A RESUMED restore: this original was re-uploaded by a previous run that died
                    // before Shopify finished processing it. It is present but not yet displayable,
                    // and nothing of ours may be deleted while an original is not READY.
                    $this->client->awaitReady($connection, $productGid, $liveId);
                }
            } else {
                $liveId = $this->reuploadOriginal($snapshot, $entry, $connection, $productGid);
            }

            if ($liveId === null) {
                continue; // no backed-up bytes (a video/3D model we never deleted) — leave it
            }

            $order[$liveId] = (int) ($entry[ShopifyMediaSnapshot::ENTRY_POSITION] ?? (count($order) + 1));
        }

        // (b) Original ORDER (and so the original featured image) is back.
        $this->client->reorder($connection, $productGid, $order);

        // (c) Only NOW may the images we added leave the gallery — EVERY media we ever minted that
        //     is still live, not merely the last one an asset row happens to remember. Their bytes
        //     are still ours (product_assets.image_path), so this destroys nothing unrecoverable.
        $ours = array_values(array_filter(
            array_unique($ourMediaIds),
            static fn (string $id): bool => isset($live[$id]),
        ));

        $confirmed = $this->client->deleteMedia($connection, $productGid, $ours);

        // A delete Shopify REPORTED but did not PERFORM would leave our image live and unlinked.
        $this->assertDeleted($ours, $confirmed);

        return $confirmed;
    }

    /** The product's CURRENT gallery — what the placement chooser shows the merchant. */
    public function gallery(Product $product, Site $site): array
    {
        return $this->client->gallery($this->connection($site), $this->productGid($product));
    }

    /**
     * Staged upload -> productCreateMedia, persisting the media id the INSTANT Shopify answers.
     * That persisted id is what makes a double-clicked push (or a retry) create exactly ONE media.
     *
     * THE CLAIM IS RE-PROVED AS THE LAST STATEMENT BEFORE THE MINT — after the byte upload, which
     * is harmless. A worker whose lease was reclaimed while it was slow-uploading stands down here
     * instead of minting a media the asset row would then forget (the B7 double-mint).
     *
     * And the mint is REMEMBERED in the same breath, twice: on the asset (the mutable pointer the
     * push resumes from) and in shopify_media_mints (append-only, never nulled — what Undo trusts).
     */
    private function createMedia(ProductAsset $asset, Product $product, ShopifyConnection $connection, string $productGid, string $claimId): string
    {
        $bytes = $this->media->get($asset->image_path);

        if ($bytes === null) {
            throw new RuntimeException(sprintf(self::MSG_NO_BYTES, (int) $asset->getKey()));
        }

        $mime = (string) ($asset->image_mime ?: self::DEFAULT_MIME);
        $resourceUrl = $this->client->upload($connection, $bytes, $this->filename($asset, $mime), $mime);

        if (! $asset->holdsPushClaim($claimId)) {
            throw PushClaimLostException::for((int) $asset->getKey());
        }

        $item = $this->client->createMedia($connection, $productGid, $resourceUrl, $this->altText($product, $asset));

        ShopifyMediaMint::record($asset, $item->id);

        $asset->forceFill(['shopify_media_id' => $item->id])->save();

        return $item->id;
    }

    /** Apply the merchant's placement to a media that is already live and READY. */
    private function place(ShopifyConnection $connection, string $productGid, string $mediaId, MediaPlacement $placement, ?ShopifyMediaSnapshot $snapshot): void
    {
        if ($placement->isPositioned()) {
            $this->client->reorder($connection, $productGid, [$mediaId => (int) $placement->position]);

            return;
        }

        if (! $placement->isReplace()) {
            return; // append — createMedia already put it at the end
        }

        $replaced = (string) $placement->replaceMediaId;
        $target = $this->client->find($connection, $productGid, $replaced);

        if ($target === null) {
            return; // already replaced (a resumed push) — the slot is ours, nothing left to delete
        }

        // Take the replaced image's slot FIRST, delete it SECOND. Between the two the gallery
        // holds both images and is perfectly valid; the reverse order would blank the slot.
        $this->client->reorder($connection, $productGid, [$mediaId => $target->position]);

        $this->deleteReplaced($connection, $productGid, $replaced, $snapshot);
    }

    /**
     * THE ONLY IRREVERSIBLE CALL IN THE SYSTEM — and the last thing that happens before it is the
     * proof that it can be undone.
     *
     * RE-PROVE REVERSIBILITY IMMEDIATELY BEFORE AN IRREVERSIBLE ACT, NOT AT THE TOP OF THE
     * FUNCTION. push() proves the bytes at :106 — and then a staged upload, a productCreateMedia
     * and a READY poll (up to 20 x 3s) run. A minute is a long time for a bucket lifecycle rule, a
     * bad purge or a racing cleanup: the objects the gate approved could be gone by the time we get
     * here, and the delete would still run — the original gone from Shopify AND from us. So the
     * bytes are read back off the disk again, as the LAST statement before the delete.
     *
     * A refusal here is a normal push failure: our media is live at the replaced slot, the ORIGINAL
     * IS STILL IN THE STORE, and nothing is lost. Undo takes our image back out (via the mint
     * ledger). The refusal is recoverable; the delete would not have been.
     */
    private function deleteReplaced(ShopifyConnection $connection, string $productGid, string $replaced, ?ShopifyMediaSnapshot $snapshot): void
    {
        if (! $snapshot instanceof ShopifyMediaSnapshot) {
            throw MediaSnapshotException::notRestorable($replaced); // unreachable: a replace is destructive
        }

        $this->assertMediaRestorable($snapshot, $replaced);

        $confirmed = $this->client->deleteMedia($connection, $productGid, [$replaced]);

        $this->assertDeleted([$replaced], $confirmed);
    }

    /**
     * Shopify said it deleted them — did it? productDeleteMedia answers with `deletedMediaIds`, and
     * an id we asked for that is NOT in that list was NOT deleted. Trusting the CALL instead of the
     * ANSWER let a still-live image be treated as gone: the asset's link was cleared, the mint was
     * forgotten by the row, and our AI image stayed on the storefront with nothing pointing at it.
     *
     * @param  array<int,string>  $requested
     * @param  array<int,string>  $confirmed
     */
    private function assertDeleted(array $requested, array $confirmed): void
    {
        $missing = array_values(array_diff($requested, $confirmed));

        if ($missing !== []) {
            throw ShopifyMediaException::deleteNotConfirmed($missing);
        }
    }

    /**
     * Re-upload ONE snapshotted original. Null when we hold no bytes for it (a video / 3D model we
     * never deleted).
     *
     * THE ID IS PERSISTED IN THE SAME BREATH AS THE CALL THAT MINTS IT — after createMedia and
     * BEFORE the READY poll, which is the step that can (and does) throw. Anything less and a
     * crash between the mint and the save loses the id, the retry re-uploads the same original,
     * and the merchant's gallery grows a duplicate original on every attempt.
     *
     * @param  array<string,mixed>  $entry
     */
    private function reuploadOriginal(ShopifyMediaSnapshot $snapshot, array $entry, ShopifyConnection $connection, string $productGid): ?string
    {
        $originalId = (string) ($entry[ShopifyMediaSnapshot::ENTRY_MEDIA_ID] ?? '');
        $path = $entry[ShopifyMediaSnapshot::ENTRY_PATH] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $bytes = $this->media->get($path);

        if ($bytes === null || $bytes === '') {
            throw MediaSnapshotException::notRestorable($originalId);
        }

        $mime = (string) ($entry[ShopifyMediaSnapshot::ENTRY_MIME] ?: self::DEFAULT_MIME);
        $filename = sprintf(
            self::FILENAME_TEMPLATE,
            self::FILENAME_ORIGINAL,
            (int) ($entry[ShopifyMediaSnapshot::ENTRY_POSITION] ?? 0),
            self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION,
        );

        $resourceUrl = $this->client->upload($connection, $bytes, $filename, $mime);
        $alt = (string) ($entry[ShopifyMediaSnapshot::ENTRY_ALT] ?? '');
        $item = $this->client->createMedia($connection, $productGid, $resourceUrl, $alt);

        // PERSIST FIRST — a crash below this line resumes instead of re-uploading.
        $snapshot->rememberRestoredMediaId($originalId, $item->id);

        // A restored original must be READY before anything else moves or is deleted.
        $this->client->awaitReady($connection, $productGid, $item->id);

        return $item->id;
    }

    /**
     * The READY gate, plus the DEAD-MEDIA escape hatch (S3): when Shopify reports our media as
     * terminally FAILED, the persisted id is worthless — every re-push would resume it and fail
     * forever. Clear it, so a re-push mints a fresh media instead of being stuck.
     */
    private function awaitReady(ProductAsset $asset, ShopifyConnection $connection, string $productGid, string $mediaId): void
    {
        try {
            $this->client->awaitReady($connection, $productGid, $mediaId);
        } catch (ShopifyMediaException $e) {
            if ($e->errorCode === ShopifyMediaException::CODE_PROCESSING_FAILED) {
                $asset->forceFill(['shopify_media_id' => null])->save();
            }

            throw $e;
        }
    }

    /**
     * A DESTRUCTIVE push may only run on a snapshot we can actually honour: every original it
     * claims must be READABLE on our disk (present, and not empty).
     *
     * A path string is not bytes. The write that produced it could have been refused by the disk
     * (every disk is `throw => false`), or the object could have been lost since — and a snapshot
     * that lies is worse than no snapshot, because it is what licenses deleting a LIVE original.
     */
    private function assertSnapshotRestorable(ShopifyMediaSnapshot $snapshot): void
    {
        foreach ($snapshot->entries() as $entry) {
            $path = $entry[ShopifyMediaSnapshot::ENTRY_PATH] ?? null;

            if ($path === null) {
                continue; // a video / 3D model — nothing to hold, and a REPLACE of it is refused
            }

            if (! is_string($path) || ! $this->media->isReadable($path)) {
                throw MediaSnapshotException::notRestorable(
                    (string) ($entry[ShopifyMediaSnapshot::ENTRY_MEDIA_ID] ?? '?')
                );
            }
        }
    }

    /**
     * A REPLACE deletes a media from a LIVE storefront, and Shopify drops its bytes with it. So it
     * may only target an original whose BYTES WE HOLD, verified by reading them back:
     *   - not in the snapshot at all (it is not an original — e.g. an image WE pushed) -> refuse;
     *   - recorded with no path (a video, a 3D model) -> refuse;
     *   - recorded with a path we cannot read back -> refuse.
     * The delete is irreversible; the refusal is not.
     *
     * AN ORIGINAL THAT WAS UNDONE IS STILL AN ORIGINAL. After a restore it lives in the store under
     * a NEW media id (Shopify mints a new object for the re-uploaded bytes), which the snapshot
     * records as `restored_media_id`. Matching only the ORIGINAL id refused every later replace on
     * that product with "it was never backed up" — which was a lie: we hold its bytes, which is why
     * we just put them back. Either id identifies the same original, and the same bytes answer for
     * both.
     */
    private function assertMediaRestorable(ShopifyMediaSnapshot $snapshot, string $mediaId): void
    {
        foreach ($snapshot->entries() as $entry) {
            if (! $this->entryIs($entry, $mediaId)) {
                continue;
            }

            $path = $entry[ShopifyMediaSnapshot::ENTRY_PATH] ?? null;

            if (is_string($path) && $this->media->isReadable($path)) {
                return;
            }

            throw MediaSnapshotException::notRestorable($mediaId);
        }

        throw MediaSnapshotException::notRestorable($mediaId);
    }

    /**
     * Does this snapshot entry describe that media — under its ORIGINAL id, or under the id a
     * restore re-uploaded it as?
     *
     * @param  array<string,mixed>  $entry
     */
    private function entryIs(array $entry, string $mediaId): bool
    {
        return (string) ($entry[ShopifyMediaSnapshot::ENTRY_MEDIA_ID] ?? '') === $mediaId
            || (string) ($entry[ShopifyMediaSnapshot::ENTRY_RESTORED_MEDIA_ID] ?? '') === $mediaId;
    }

    /**
     * The alt text this image carries into the store: the DB/config template with the product's
     * name + the operation substituted by strtr(). NEVER Blade::render() — this is
     * admin-editable text, and rendering it would be an RCE.
     */
    private function altText(Product $product, ProductAsset $asset): string
    {
        $template = (string) (config(self::CFG_ALT_TEMPLATE) ?: self::DEFAULT_ALT_TEMPLATE);

        return strtr($template, [
            self::VAR_PRODUCT_NAME => (string) $product->name,
            self::VAR_OPERATION => (string) __(self::OPERATION_LABEL_KEY.$asset->operation_key),
        ]);
    }

    private function filename(ProductAsset $asset, string $mime): string
    {
        return sprintf(
            self::FILENAME_TEMPLATE,
            (string) $asset->operation_key,
            (int) $asset->getKey(),
            self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION,
        );
    }

    /**
     * The original as it is in the store RIGHT NOW — under its own id, or under the id a previous
     * restore re-uploaded it as (which is why that id is persisted the moment it is minted).
     *
     * @param  array<string,mixed>  $entry
     * @param  array<string,ShopifyMediaItem>  $live
     */
    private function liveOriginal(array $entry, array $live): ?ShopifyMediaItem
    {
        $restored = (string) ($entry[ShopifyMediaSnapshot::ENTRY_RESTORED_MEDIA_ID] ?? '');

        if ($restored !== '' && isset($live[$restored])) {
            return $live[$restored];
        }

        $original = (string) ($entry[ShopifyMediaSnapshot::ENTRY_MEDIA_ID] ?? '');

        return $live[$original] ?? null;
    }

    /** @param array<int,ShopifyMediaItem> $items @return array<string,ShopifyMediaItem> */
    private function byId(array $items): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$item->id] = $item;
        }

        return $keyed;
    }

    /** The site's INSTALLED connection, through the tenant-scoped relation (fail closed). */
    private function connection(Site $site): ShopifyConnection
    {
        $connection = $site->shopifyConnection;

        if (! $connection instanceof ShopifyConnection || ! $connection->isInstalled()) {
            throw new RuntimeException(sprintf(self::MSG_NO_CONNECTION, (int) $site->getKey()));
        }

        return $connection;
    }

    private function productGid(Product $product): string
    {
        $gid = (string) ($product->external_id ?? '');

        if ($gid === '' || ! $product->isShopify()) {
            throw new RuntimeException(sprintf(self::MSG_NOT_SHOPIFY, (int) $product->getKey()));
        }

        return $gid;
    }
}
