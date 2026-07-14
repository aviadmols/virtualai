<?php

namespace Tests\Feature\Shopify;

use App\Domain\Media\MediaStorage;
use App\Domain\Media\MediaWriteException;
use App\Domain\ProductImages\ProductImageReview;
use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushProductMediaJob;
use App\Domain\Shopify\Media\PushResult;
use App\Domain\Shopify\Media\ShopifyMediaException;
use App\Domain\Shopify\Media\UndoProductMediaJob;
use App\Models\ProductAsset;
use App\Models\ShopifyMediaMint;
use App\Models\ShopifyMediaSnapshot;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * THE DESTRUCTIVE RAIL — the walls that stand between a merchant and a permanently lost image.
 *
 * Phase 5 lets an image be REPLACED in a live storefront. Shopify drops a media's bytes the moment
 * the media object is deleted, so a delete we cannot undo is a delete forever. This suite exists to
 * answer ONE question with a NO:
 *
 *   "Can any sequence of failures, retries, races or crashes leave a merchant's live product with a
 *    deleted original image we cannot restore?"
 *
 * Every test here is a guard that was, at some point, missing — and every one of them RED-tests the
 * guard itself (delete the guard, this file goes red), never merely the happy path around it:
 *
 *   B3  a snapshot write is VERIFIED, not attempted (put() returns FALSE on every `throw => false`
 *       disk) — and a snapshot may not be stamped CAPTURED until its bytes read back;
 *   B4  a REPLACE may only target an original whose BYTES WE HOLD (not a video, not a lost object);
 *   B1  a crash mid-restore never re-uploads an original twice (the id is persisted in the same
 *       breath as the call that mints it);
 *   B2  a snapshot holds the merchant's TRUE originals — never our own pushed image — so a second
 *       undo can never re-inject our AI image into the live storefront;
 *   B5  a gallery bigger than one page is read WHOLE, or the push is refused (fail closed);
 *   B6  a review move can never brick the undo rail.
 */
class ShopifyMediaSafetyTest extends TestCase
{
    use RefreshDatabase, ShopifyMediaTestSupport;

    // === CONSTANTS ===
    private const CFG_PER_PRODUCT = 'shopify.media.per_product';

    private const CFG_MAX_PAGES = 'shopify.media.max_pages';

    private const REFUSED_MESSAGE = 'could not be restored';

    private const CAPTURE_FAILED_MESSAGE = 'back up the original images';

    private const TRUNCATED_MESSAGE = 'could not be read completely';

    // Past shopify.media.stuck_after_minutes (default 30) — the push is LOST, not in flight.
    private const STUCK_MINUTES = 31;

    // The claim a parked continuation carries back to renew its own lease.
    private const PARKED_CLAIM = 'claim-of-the-parked-worker';

    // Reads of a new media before Shopify calls it READY (= shopify.media.ready_attempts here).
    private const SLOW_PROCESSING_POLLS = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootShopifyMediaEnv();
        $this->fakeShopifyStore();
        Bus::fake([PushProductMediaJob::class, UndoProductMediaJob::class]);
    }

    private function runPush(array $shop, ProductAsset $asset, MediaPlacement $placement): void
    {
        (new PushProductMediaJob(
            (int) $shop['account']->getKey(),
            (int) $shop['site']->getKey(),
            (int) $asset->getKey(),
            $placement->toArray(),
        ))->handle();
    }

    private function runUndo(array $shop): void
    {
        (new UndoProductMediaJob(
            (int) $shop['account']->getKey(),
            (int) $shop['site']->getKey(),
            (int) $shop['product']->getKey(),
        ))->handle();
    }

    private function snapshotOf(array $shop): ?ShopifyMediaSnapshot
    {
        return Tenant::run($shop['account'], fn () => ShopifyMediaSnapshot::query()->first());
    }

    // ---------------------------------------------------------------------------------------
    // B3 — A WRITE ATTEMPTED IS NOT A WRITE VERIFIED.
    // ---------------------------------------------------------------------------------------

    /**
     * THE BLAST-RADIUS TEST. The media disk REFUSES the snapshot write (put() returns FALSE — every
     * disk is `throw => false`, so a failed write does NOT raise). Before this wall existed, the
     * snapshot still got a path, was stamped CAPTURED, and the pusher happily DELETED the merchant's
     * original from the live store — which was then gone from Shopify AND from us.
     *
     * Delete the put()-boolean check in MediaStorage::write() -> RED (the original is deleted).
     */
    public function test_a_refused_snapshot_write_never_lets_the_original_be_deleted(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        $this->breakMediaDiskWrites();

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $asset->refresh();

        // The push is REFUSED and the store is untouched: the original is still there.
        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertStringContainsString(self::CAPTURE_FAILED_MESSAGE, (string) $asset->push_error);
        $this->assertSame($before, $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());
        $this->assertSame(0, $this->createdMediaCount());

        // And the snapshot is FAILED — never CAPTURED on the strength of bytes that never landed.
        $this->assertSame(ShopifyMediaSnapshot::STATUS_FAILED, $this->snapshotOf($shop)?->status);
    }

    /**
     * The nastier disk: put() says YES and the object is not there (a lying or eventually-consistent
     * backend). The READBACK is the only thing that catches it.
     *
     * Delete the readback in MediaStorage::write() -> RED.
     */
    public function test_a_snapshot_write_that_cannot_be_read_back_never_lets_the_original_be_deleted(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        $this->breakMediaDiskReadback();

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->refresh()->push_status);
        $this->assertSame($before, $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());
        $this->assertSame(ShopifyMediaSnapshot::STATUS_FAILED, $this->snapshotOf($shop)?->status);
    }

    /** The rail-wide law, at its root: MediaStorage never returns a path it did not verify. */
    public function test_media_storage_throws_a_typed_failure_when_the_disk_refuses_a_write(): void
    {
        $this->breakMediaDiskWrites();

        $this->expectException(MediaWriteException::class);

        app(MediaStorage::class)->storeResult(1, 2, 3, self::ASSET_BYTES, 'image/png');
    }

    /**
     * The put() boolean is checked ON ITS OWN MERIT, not just implied by the readback: a disk that
     * REFUSES the write while stale bytes happen to sit at that path would sail through a readback.
     * OUR bytes did not land, and that is the only thing that matters.
     *
     * Delete the `=== false` check in MediaStorage::write() -> RED.
     */
    public function test_media_storage_throws_when_the_disk_refuses_a_write_even_if_the_path_reads_back(): void
    {
        $this->breakMediaDiskWritesOverStaleBytes();

        $this->expectException(MediaWriteException::class);

        app(MediaStorage::class)->storeShopifySnapshot(1, 2, 3, self::ASSET_BYTES, 'image/png');
    }

    /** ...and never returns a path whose object cannot be read back, even when put() said yes. */
    public function test_media_storage_throws_a_typed_failure_when_the_write_cannot_be_verified(): void
    {
        $this->breakMediaDiskReadback();

        $this->expectException(MediaWriteException::class);

        app(MediaStorage::class)->storeProductAsset(1, 2, 3, self::ASSET_BYTES, 'image/png');
    }

    // ---------------------------------------------------------------------------------------
    // B4 — A REPLACE MAY ONLY DELETE WHAT WE CAN PUT BACK (assertMediaRestorable).
    // ---------------------------------------------------------------------------------------

    /**
     * A Shopify VIDEO (or a 3D model) has no downloadable image: the snapshot records it with a null
     * path, and we can NEVER hand those bytes back. Replacing it would delete it forever.
     *
     * Neuter assertMediaRestorable() -> RED (the video is deleted from the live store).
     */
    public function test_a_replace_that_targets_a_media_we_cannot_back_up_is_refused_and_deletes_nothing(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $videoId = $this->seedVideo();
        $before = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::replace($videoId));

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertStringContainsString(self::REFUSED_MESSAGE, (string) $asset->push_error);

        // NOTHING was deleted, and the video is still in the merchant's gallery.
        $this->assertNotContains('delete', $this->storeOps());
        $this->assertContains($videoId, $this->galleryIds());
        $this->assertSame($before, $this->galleryIds());

        // The snapshot recorded the video with NO path — that is exactly why the push was refused.
        $entries = $this->snapshotOf($shop)->entries();
        $video = array_values(array_filter(
            $entries,
            fn (array $e): bool => $e[ShopifyMediaSnapshot::ENTRY_MEDIA_ID] === $videoId,
        ))[0];

        $this->assertNull($video[ShopifyMediaSnapshot::ENTRY_PATH]);
    }

    /**
     * The snapshot exists, is CAPTURED, and its path is a perfectly good string — but the OBJECT is
     * gone from the disk. A path is not bytes. Replacing on the strength of that string would delete
     * an original nobody can restore.
     *
     * Weaken assertMediaRestorable() back to "the path string is non-empty" -> RED.
     */
    public function test_a_replace_is_refused_when_the_snapshot_bytes_are_missing_from_the_disk(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $first = $this->approvedAsset($shop);
        $second = $this->approvedAsset($shop);
        $target = $this->galleryIds()[0];

        // A first destructive push captures the snapshot honestly.
        $this->runPush($shop, $first, MediaPlacement::position(2));

        // ...and then the backup object is LOST (a bucket lifecycle rule, a bad purge, a bit-rot).
        $snapshot = $this->snapshotOf($shop);

        foreach ($snapshot->entries() as $entry) {
            Storage::disk('s3')->delete((string) $entry[ShopifyMediaSnapshot::ENTRY_PATH]);
        }

        $before = $this->galleryIds();
        $this->storeLog = [];

        $this->runPush($shop, $second, MediaPlacement::replace($target));

        $second->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $second->push_status);
        $this->assertStringContainsString(self::REFUSED_MESSAGE, (string) $second->push_error);
        $this->assertNotContains('delete', $this->storeOps());
        $this->assertContains($target, $this->galleryIds());
        $this->assertSame($before, $this->galleryIds());
    }

    /**
     * The whole-snapshot wall (assertSnapshotRestorable): even a placement that deletes NOTHING (a
     * reorder) may not run on a snapshot we cannot honour — because undo would then be unable to put
     * the gallery back, and the merchant would have lost their featured image for good.
     *
     * Delete assertSnapshotRestorable() -> RED.
     */
    public function test_a_destructive_reorder_is_refused_when_a_snapshotted_original_is_unreadable(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $first = $this->approvedAsset($shop);
        $second = $this->approvedAsset($shop);

        $this->runPush($shop, $first, MediaPlacement::position(2));

        $snapshot = $this->snapshotOf($shop);

        // The backup of original #1 is truncated to zero bytes: it EXISTS, and it is worthless.
        Storage::disk('s3')->put((string) $snapshot->entries()[0][ShopifyMediaSnapshot::ENTRY_PATH], '');

        $before = $this->galleryIds();
        $this->storeLog = [];

        $this->runPush($shop, $second, MediaPlacement::position(1));

        $this->assertSame(ProductAsset::PUSH_FAILED, $second->refresh()->push_status);
        $this->assertSame($before, $this->galleryIds(), 'The gallery must not be reordered.');
        $this->assertSame([], $this->storeOps());
    }

    // ---------------------------------------------------------------------------------------
    // B1 — A CRASH MID-RESTORE MUST NOT DUPLICATE THE ORIGINALS.
    // ---------------------------------------------------------------------------------------

    /**
     * Undo re-uploads a deleted original, and the worker dies before it finishes (the READY poll
     * budget runs out — the same failure the push rail already survives). The merchant clicks Undo
     * again.
     *
     * The re-uploaded media id MUST have been persisted the instant Shopify minted it, or the retry
     * uploads the SAME original a second time and the live gallery keeps a DUPLICATE original —
     * one more on every retry.
     *
     * Move the rememberRestoredMediaId() call back below awaitReady() -> RED (4 media, not 3).
     */
    public function test_a_crash_mid_restore_never_re_uploads_the_same_original_twice(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        [$first, $second] = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::replace($first));

        $pushedId = (string) $asset->refresh()->shopify_media_id;
        $this->assertNotContains($first, $this->galleryIds()); // the original is gone from Shopify

        $this->storeLog = []; // from here on, every `create` is a RE-UPLOAD of an original

        // UNDO #1: Shopify never finishes processing the re-uploaded original -> the job throws
        // AFTER the media was minted. This is the crash window.
        $this->readyAfterPolls = 99;

        try {
            $this->runUndo($shop);
            $this->fail('The restore must surface the exhausted READY budget.');
        } catch (RuntimeException) {
            // expected — the worker dies here
        }

        $mintedOnFirstTry = $this->createdMediaCount();
        $this->assertSame(1, $mintedOnFirstTry, 'The original was re-uploaded once.');

        // The id was persisted IN THE SAME BREATH as the call that minted it.
        $entry = $this->snapshotOf($shop)->entries()[0];
        $restoredId = (string) ($entry[ShopifyMediaSnapshot::ENTRY_RESTORED_MEDIA_ID] ?? '');

        $this->assertNotSame('', $restoredId, 'The restored media id must be persisted before the poll.');
        $this->assertContains($restoredId, $this->galleryIds());

        // UNDO #2: the store is healthy again. It must RESUME, not re-upload.
        $this->readyAfterPolls = 1;
        $this->runUndo($shop);

        $this->assertSame(1, $this->createdMediaCount(), 'A retry must never re-upload the same original.');

        $gallery = $this->galleryIds();

        $this->assertCount(2, $gallery, 'Two originals — not three, and no duplicate.');
        $this->assertSame([$restoredId, $second], $gallery);
        $this->assertNotContains($pushedId, $gallery);
        $this->assertSame(ProductAsset::PUSH_NOT_PUSHED, $asset->refresh()->push_status);
    }

    // ---------------------------------------------------------------------------------------
    // B2 — THE SNAPSHOT HOLDS THE MERCHANT'S ORIGINALS, NEVER OURS.
    // ---------------------------------------------------------------------------------------

    /**
     * An APPEND is not destructive, so it takes no snapshot. The FIRST destructive push therefore
     * meets a gallery that ALREADY CONTAINS an image we pushed. If that image is captured as an
     * "original", undo #1 correctly deletes it — and undo #2 sees a missing "original" and
     * RE-UPLOADS OUR AI IMAGE back into the live storefront, where it stays forever.
     *
     * Delete the ourMediaIds() exclusion in ShopifyMediaSnapshotter::capture() -> RED.
     */
    public function test_our_own_pushed_image_is_never_snapshotted_as_an_original(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $appended = $this->approvedAsset($shop);
        $replacing = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        // 1. APPEND (free, non-destructive, no snapshot) — our image is now in the live gallery.
        $this->runPush($shop, $appended, MediaPlacement::append());
        $appendedId = (string) $appended->refresh()->shopify_media_id;

        $this->assertContains($appendedId, $this->galleryIds());

        // 2. REPLACE (destructive) — the snapshot is taken NOW, with our image already in there.
        $this->runPush($shop, $replacing, MediaPlacement::replace($originals[0]));

        $snapshot = $this->snapshotOf($shop);
        $snapshotIds = array_map(
            static fn (array $e): string => (string) $e[ShopifyMediaSnapshot::ENTRY_MEDIA_ID],
            $snapshot->entries(),
        );

        // The snapshot is the merchant's TRUE original state: two originals, and nothing of ours.
        $this->assertSame($originals, $snapshotIds);
        $this->assertNotContains($appendedId, $snapshotIds);

        // 3. UNDO, twice. The gallery must land on the ORIGINALS and STAY there.
        $this->runUndo($shop);
        $afterFirst = $this->galleryIds();

        $this->assertCount(2, $afterFirst);
        $this->assertNotContains($appendedId, $afterFirst, 'Our appended image left the store.');
        $this->assertNotContains((string) $replacing->refresh()->shopify_media_id, $afterFirst);

        $this->runUndo($shop);

        $this->assertSame($afterFirst, $this->galleryIds(), 'A second undo re-injects nothing.');
        $this->assertCount(2, $this->galleryIds(), 'Our AI image must never come back as an "original".');
    }

    // ---------------------------------------------------------------------------------------
    // B5 — A GALLERY BIGGER THAN ONE PAGE IS READ WHOLE, OR THE PUSH IS REFUSED.
    // ---------------------------------------------------------------------------------------

    /**
     * A big gallery (60 media, page size 50) used to be snapshotted as its first 50 and stamped
     * CAPTURED. The other 10 existed only in the store — and undo could never restore their order.
     *
     * Drop the pagination walk in ShopifyMediaClient::gallery() -> RED (the snapshot holds 2, not 5).
     */
    public function test_a_gallery_larger_than_one_page_is_snapshotted_whole(): void
    {
        config()->set(self::CFG_PER_PRODUCT, 2); // 5 originals over 3 pages

        $shop = $this->mediaShop(originals: 5);
        $asset = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::position(1));

        $snapshot = $this->snapshotOf($shop);

        $this->assertCount(5, $snapshot->entries(), 'Every original must be in the snapshot.');
        $this->assertSame($originals, array_map(
            static fn (array $e): string => (string) $e[ShopifyMediaSnapshot::ENTRY_MEDIA_ID],
            $snapshot->entries(),
        ));

        // The positions are the merchant's real 1..N order, so undo can put them back.
        $this->assertSame([1, 2, 3, 4, 5], array_map(
            static fn (array $e): int => (int) $e[ShopifyMediaSnapshot::ENTRY_POSITION],
            $snapshot->entries(),
        ));
    }

    /**
     * A gallery we CANNOT read to its end (Shopify says "there is more" and gives no cursor, or the
     * page budget runs out) is NOT a shorter gallery. It fails closed: nothing is snapshotted as
     * complete, and no destructive push runs on a partial truth.
     *
     * Delete the galleryUnread() throws -> RED (a truncated snapshot licenses the push).
     */
    public function test_a_gallery_that_cannot_be_read_to_the_end_refuses_the_destructive_push(): void
    {
        config()->set(self::CFG_PER_PRODUCT, 2);

        $shop = $this->mediaShop(originals: 5);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        $this->galleryCursorBroken = true; // "hasNextPage: true" with no endCursor

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertStringContainsString(self::TRUNCATED_MESSAGE, (string) $asset->push_error);
        $this->assertSame($before, $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());
        $this->assertSame(0, $this->createdMediaCount());
    }

    /** The page budget is a wall too: a gallery that outruns it fails closed, it does not truncate. */
    public function test_a_gallery_that_outruns_the_page_budget_fails_closed(): void
    {
        config()->set(self::CFG_PER_PRODUCT, 1);
        config()->set(self::CFG_MAX_PAGES, 2); // 2 x 1 = 2 media readable; the product holds 4

        $shop = $this->mediaShop(originals: 4);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->refresh()->push_status);
        $this->assertStringContainsString(self::TRUNCATED_MESSAGE, (string) $asset->push_error);
        $this->assertSame($before, $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());
    }

    // ---------------------------------------------------------------------------------------
    // B6 — A REVIEW MOVE CAN NEVER BRICK THE UNDO RAIL.
    // ---------------------------------------------------------------------------------------

    /**
     * Rejecting an image that is LIVE in the store is refused: the two machines must agree, or the
     * panel says "rejected" about an image the shopper is still looking at — and undo would then
     * throw AFTER it had already mutated the store, stranding the asset at `pushed` forever.
     *
     * Delete the isInStore() guard in reviewTransitionTo() -> RED.
     */
    public function test_an_image_that_is_live_in_the_store_cannot_be_rejected(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::append());
        $asset->refresh();

        $this->assertTrue($asset->isPushed());

        $this->expectException(RuntimeException::class);

        Tenant::run($shop['account'], fn () => $asset->reviewTransitionTo(ProductAsset::REVIEW_REJECTED));
    }

    /** The service says so with a typed FALSE, never a 500 — the merchant gets an explanation. */
    public function test_the_review_service_refuses_to_reject_a_pushed_image_without_throwing(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::append());

        $review = app(ProductImageReview::class);

        [$blocked, $rejected] = Tenant::run($shop['account'], fn (): array => [
            $review->isBlockedByStore($shop['site'], (int) $asset->getKey()),
            $review->reject($shop['site'], (int) $asset->getKey()),
        ]);

        $this->assertTrue($blocked);
        $this->assertFalse($rejected);
        $this->assertSame(ProductAsset::REVIEW_APPROVED, $asset->refresh()->review_status);
    }

    /**
     * THE BRICK TEST. Even if an asset somehow ends up REJECTED while its image is live in the store
     * (a legacy row, a race, a direct write), UNDO MUST STILL CLOSE THE LOOP: leaving the storefront
     * needs no approval. Otherwise pushTransitionTo() throws AFTER the store was already restored —
     * the asset stays `pushed` with a dead media id, no restore event is written, and every later
     * Undo click throws again.
     *
     * Restore the blanket approval gate in pushTransitionTo() -> RED.
     */
    public function test_undo_still_closes_the_loop_for_an_asset_that_was_rejected_while_pushed(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        [$first, $second] = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::replace($first));
        $asset->refresh();

        // The asset is rejected out from under the push (the state the old code could reach).
        Tenant::run($shop['account'], fn () => $asset->forceFill([
            'review_status' => ProductAsset::REVIEW_REJECTED,
        ])->save());

        $this->runUndo($shop);

        $asset->refresh();

        // The store is back to the originals AND the asset closed cleanly — no brick.
        $this->assertSame(ProductAsset::PUSH_NOT_PUSHED, $asset->push_status);
        $this->assertNull($asset->shopify_media_id);
        $this->assertCount(2, $this->galleryIds());
        $this->assertContains($second, $this->galleryIds());
        $this->assertSame(1, (int) $this->snapshotOf($shop)->restore_count);
    }

    // ---------------------------------------------------------------------------------------
    // THE SNAPSHOT MAY NOT BE STAMPED `captured` ON BYTES IT CANNOT READ BACK.
    // ---------------------------------------------------------------------------------------

    /**
     * The individual writes all succeeded — and then the objects VANISHED mid-capture (a bucket
     * lifecycle rule, a bad purge, a racing cleanup). `captured` is a PROMISE: it is what makes the
     * panel offer an Undo button and what licenses a destructive push. It may only be stamped on
     * bytes we can actually read back.
     *
     * Delete assertVerified() in ShopifyMediaSnapshotter::ensure() -> RED (the snapshot is stamped
     * CAPTURED, and the merchant is offered an Undo that cannot work).
     */
    public function test_a_snapshot_whose_objects_vanish_mid_capture_is_never_stamped_captured(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        $this->purgeSnapshotsMidCapture = true;

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->refresh()->push_status);
        $this->assertSame(ShopifyMediaSnapshot::STATUS_FAILED, $this->snapshotOf($shop)?->status);

        // No Undo is offered for a snapshot we cannot honour, and the store is untouched.
        $this->assertFalse(Tenant::run($shop['account'], fn (): bool => app(PushProductMedia::class)
            ->hasSnapshot($shop['site'], (int) $shop['product']->getKey())));

        $this->assertSame($before, $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());
    }

    // ---------------------------------------------------------------------------------------
    // S2 / S3 — A LOST PUSH AND A DEAD MEDIA ARE BOTH RECOVERABLE.
    // ---------------------------------------------------------------------------------------

    /**
     * S2 — the killed worker. A SIGKILL/OOM never calls failed(), so the asset stays `pushing`.
     * Push denies IN_FLIGHT and re-push denies too: that image could never reach the store again.
     * Past the stuck window the merchant may reclaim it — and the reclaim RESUMES the persisted
     * media id, so it can never mint a second copy.
     *
     * Delete isPushStuck() from PushProductMedia::push() -> RED (denied IN_FLIGHT forever).
     */
    public function test_a_push_whose_worker_was_killed_is_reclaimable_and_never_duplicates_the_media(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        // The worker died mid-push, AFTER Shopify had already minted the media.
        Tenant::run($shop['account'], fn () => $asset->forceFill([
            'push_status' => ProductAsset::PUSH_PUSHING,
            'shopify_media_id' => 'gid://shopify/MediaImage/999',
        ])->save());

        // Still in flight: a fresh push is (correctly) denied.
        $denied = Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
            ->push($shop['site'], (int) $asset->getKey(), MediaPlacement::append()));

        $this->assertTrue($denied->wasDenied());
        $this->assertSame(PushResult::REASON_IN_FLIGHT, $denied->deniedReason);

        // The stuck window passes. It is not in flight; it is LOST.
        $this->travel(31)->minutes();

        $queued = Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
            ->push($shop['site'], (int) $asset->getKey(), MediaPlacement::append()));

        $this->assertTrue($queued->queued);

        // The reclaiming job RESUMES the media it already minted — it does not upload a second copy.
        $this->runPush($shop, $asset->refresh(), MediaPlacement::append());

        $this->assertSame(0, $this->createdMediaCount(), 'A reclaim must never mint a second media.');
    }

    /**
     * S3 — the dead media. Shopify processed our image to FAILED; the persisted id is worthless, and
     * every re-push would resume it and fail forever. The id is cleared, so a re-push mints a fresh
     * media and the merchant is not stuck with an image that can never be published.
     *
     * Delete the CODE_PROCESSING_FAILED branch in ShopifyMediaPusher::awaitReady() -> RED.
     */
    public function test_a_media_shopify_reports_as_failed_is_cleared_so_a_re_push_can_mint_a_fresh_one(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->processingFails = true;
        $this->runPush($shop, $asset, MediaPlacement::append());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertNull($asset->shopify_media_id, 'A dead media id must not be resumed forever.');
        $this->assertSame(1, $this->createdMediaCount());

        // Shopify is healthy again; the merchant re-pushes and gets a NEW, working media.
        $this->processingFails = false;
        $this->runPush($shop, $asset->refresh(), MediaPlacement::append());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertNotNull($asset->shopify_media_id);
        $this->assertSame(2, $this->createdMediaCount());
        $this->assertContains((string) $asset->shopify_media_id, $this->galleryIds());
    }

    // ---------------------------------------------------------------------------------------
    // M12 — THE SNAPSHOT'S OWN STATE MACHINE IS GUARDED.
    // ---------------------------------------------------------------------------------------

    /**
     * `captured` is terminal on purpose: a re-capture would overwrite the ORIGINAL original state
     * with whatever the gallery looks like now (i.e. with our images in it). The guard is what makes
     * "the original state is the original state" true.
     *
     * Delete the TRANSITIONS check in ShopifyMediaSnapshot::transitionTo() -> RED.
     */
    public function test_an_illegal_snapshot_transition_is_rejected(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::position(1));

        $snapshot = $this->snapshotOf($shop);
        $this->assertTrue($snapshot->isCaptured());

        $this->expectException(RuntimeException::class);

        Tenant::run($shop['account'], fn () => $snapshot->transitionTo(ShopifyMediaSnapshot::STATUS_CAPTURING));
    }

    // ---------------------------------------------------------------------------------------
    // B8 — VERIFY THE PREDICATE YOU CARE ABOUT, NOT A PROXY FOR IT.
    //
    // The readback existed and it asked the WRONG QUESTION: "is the object at least 1 byte?"
    // A TRUNCATED object answers yes. And this is not a hypothetical disk — MEDIA_DISK=volume (a
    // Railway Volume, the local driver) writes with file_put_contents, which on a FULL disk performs
    // a SHORT WRITE and returns a BYTE COUNT, not false. So "the volume is full" used to mean "the
    // original is gone from Shopify and what we hold is 1 byte of it".
    // ---------------------------------------------------------------------------------------

    /**
     * The root law: OUR bytes, ALL of them, or nothing.
     *
     * Delete the `$stored !== $expected` clause in MediaStorage::write() -> RED.
     */
    public function test_media_storage_throws_when_the_disk_short_writes(): void
    {
        $this->breakMediaDiskWithShortWrites();

        $this->expectException(MediaWriteException::class);

        app(MediaStorage::class)->storeShopifySnapshot(1, 2, 3, self::ORIGINAL_BYTES, 'image/png');
    }

    /**
     * THE BLAST RADIUS. The disk accepts the snapshot write and stores ONE BYTE of it. Every
     * "does it exist / is it non-empty?" gate passes. Without the byte-count check the snapshot is
     * stamped CAPTURED, the merchant's live original is DELETED, and the image we hand back on undo
     * is not the image.
     *
     * Delete the `$stored !== $expected` clause in MediaStorage::write() -> RED.
     */
    public function test_a_short_written_snapshot_never_lets_the_original_be_deleted(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        $this->breakMediaDiskWithShortWrites();

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertStringContainsString(self::CAPTURE_FAILED_MESSAGE, (string) $asset->push_error);

        // The store is byte-identical: nothing was created, nothing was deleted.
        $this->assertSame($before, $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());
        $this->assertSame(0, $this->createdMediaCount());

        // And the snapshot is FAILED — never CAPTURED on the strength of a truncated object.
        $this->assertSame(ShopifyMediaSnapshot::STATUS_FAILED, $this->snapshotOf($shop)?->status);
    }

    // ---------------------------------------------------------------------------------------
    // B9 — RE-PROVE REVERSIBILITY IMMEDIATELY BEFORE AN IRREVERSIBLE ACT.
    // ---------------------------------------------------------------------------------------

    /**
     * The pre-flight gates pass HONESTLY: the bytes are on the disk and they read back. Then a whole
     * minute of the real world happens — a staged upload, a productCreateMedia, a 20 x 3s READY poll
     * — and during it a bucket lifecycle rule / a bad purge / a racing cleanup takes the backups
     * away. (The phase's own snapshot test already treats that threat as real.)
     *
     * The delete used to run anyway, on a proof that was 60 seconds stale: the original gone from
     * Shopify AND from us. The bytes are now re-read as the LAST statement before the delete.
     *
     * A refusal here costs nothing: our media is live in the slot, the ORIGINAL IS STILL THERE, and
     * Undo takes our image back out.
     *
     * Delete the assertMediaRestorable() re-assert in deleteReplaced() -> RED.
     */
    public function test_a_replace_is_refused_when_the_snapshot_bytes_vanish_after_the_ready_gate(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $target = $this->galleryIds()[0];

        // The backups disappear DURING the staged upload — after the gates, before the delete.
        $this->purgeSnapshotsOnUpload = true;

        $this->runPush($shop, $asset, MediaPlacement::replace($target));

        // THE MERCHANT'S ORIGINAL IS STILL IN THEIR LIVE GALLERY. Nothing was destroyed.
        $this->assertContains($target, $this->galleryIds(), 'The original was DELETED on a byte proof that was already stale.');
        $this->assertNotContains('delete', $this->storeOps(), 'The irreversible call ran without a fresh proof.');

        // We got as far as creating (and even reordering) our media — and then STOPPED.
        $this->assertContains('create', $this->storeOps());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertStringContainsString(self::REFUSED_MESSAGE, (string) $asset->push_error);
    }

    // ---------------------------------------------------------------------------------------
    // B7 — THE LEASE. A reclaim of a "stuck" push must never mint a SECOND media.
    // ---------------------------------------------------------------------------------------

    /**
     * THE DOUBLE-MINT RACE, in full.
     *
     * A push takes the lease and goes to Shopify. It then HANGS (a frozen worker, a stalled socket)
     * past the stuck window. The merchant reclaims it — correctly, because from the outside it is
     * indistinguishable from a killed worker. The reclaim mints the media.
     *
     * And then the "lost" worker WAKES UP, right where it left off: about to mint. It has no media
     * id (that is precisely the case the reclaim exists for, and precisely the case the resume wall
     * cannot cover). Before the lease it minted a SECOND media into the live gallery, the asset row
     * kept only the LAST id, and no Undo could ever take the other one out.
     *
     * Now it re-proves its claim as the last statement before the mint, finds a stranger holding the
     * lease, and STANDS DOWN.
     *
     * Delete the assertClaim (holdsPushClaim) check in ShopifyMediaPusher::createMedia() -> RED
     * (two `create` calls, two AI images in the merchant's storefront).
     */
    public function test_a_reclaim_of_a_stuck_push_with_no_media_id_never_mints_a_second_media(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);
        $reclaimed = false;

        // The reclaim runs while the first worker is still uploading its bytes — the real
        // interleaving, not a simulated one.
        $this->onStagedUpload = function () use ($shop, $asset, &$reclaimed): void {
            if ($reclaimed) {
                return;
            }

            $reclaimed = true;

            // The first worker has been hanging for longer than the stuck window.
            $this->travel(self::STUCK_MINUTES)->minutes();

            // The merchant reclaims: a fresh claim, which EVICTS the hung worker.
            $this->runPush($shop, $asset->fresh(), MediaPlacement::append());
        };

        $this->runPush($shop, $asset, MediaPlacement::append());

        $this->assertTrue($reclaimed, 'The reclaim must have raced the original push.');

        // EXACTLY ONE media in the merchant's store — the reclaim's. The evicted worker stood down.
        $this->assertSame(1, $this->createdMediaCount(), 'A reclaim must never mint a second media.');

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertContains((string) $asset->shopify_media_id, $this->galleryIds());
        $this->assertCount(2, $this->galleryIds(), 'One original + exactly one image of ours.');

        // The mint ledger agrees: we put ONE media in their store.
        $this->assertCount(1, $this->mintedIds($shop));
    }

    /**
     * A LEASE MUST RE-STAMP THE FIELD IT IS JUDGED BY.
     *
     * isPushStuck() judges freshness by `updated_at`. A parked continuation that renews its OWN
     * claim writes the same claim id — so without an explicit `updated_at` re-stamp the row is not
     * dirty, nothing is written, and the lease keeps ageing while the push is very much alive. A
     * reclaimer then walks in behind a LIVING worker.
     *
     * Delete `'updated_at' => now()` from ProductAsset::takePushLease() -> RED (the reclaim is
     * admitted instead of denied IN_FLIGHT).
     */
    public function test_a_renewed_push_lease_is_fresh_and_a_second_worker_is_refused(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        // A push that parked once: `pushing`, holding its claim, and its lease has gone stale in a
        // queue backlog (it is SLOW, not dead).
        Tenant::run($shop['account'], fn () => $asset->forceFill([
            'push_status' => ProductAsset::PUSH_PUSHING,
            'push_claim_id' => self::PARKED_CLAIM,
            'shopify_media_id' => null,
        ])->save());

        $this->travel(self::STUCK_MINUTES)->minutes();

        $verdict = null;

        // While the continuation is mid-flight (it has already renewed its lease), a second worker
        // asks whether it may take over.
        $this->onStagedUpload = function () use ($shop, $asset, &$verdict): void {
            $verdict ??= Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
                ->push($shop['site'], (int) $asset->getKey(), MediaPlacement::append()));
        };

        (new PushProductMediaJob(
            (int) $shop['account']->getKey(),
            (int) $shop['site']->getKey(),
            (int) $asset->getKey(),
            MediaPlacement::append()->toArray(),
            parks: 1,
            claimId: self::PARKED_CLAIM,
        ))->handle();

        $this->assertInstanceOf(PushResult::class, $verdict);

        // The lease is SECONDS old, not 31 minutes: the push is alive and nobody may take it.
        $this->assertTrue($verdict->wasDenied());
        $this->assertSame(PushResult::REASON_IN_FLIGHT, $verdict->deniedReason);

        $this->assertSame(1, $this->createdMediaCount());
        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->refresh()->push_status);
    }

    // ---------------------------------------------------------------------------------------
    // B7(d) / S8 — THE MINT LEDGER: WHAT WE PUT IN, WE CAN ALWAYS TAKE OUT.
    // ---------------------------------------------------------------------------------------

    /**
     * THE ORPHAN. `product_assets.shopify_media_id` is a MUTABLE POINTER, and one of the paths that
     * clears it needs no race at all to reach: Shopify processes our image to FAILED, so the id is
     * worthless and is dropped (or every re-push would resume a dead media forever) — while THE
     * MEDIA OBJECT IS STILL IN THE MERCHANT'S GALLERY.
     *
     * The asset re-pushes, succeeds, and now carries a DIFFERENT id. An Undo driven off that column
     * removes only the second one: our first AI image stays live in the storefront forever, and
     * "restore my original images" is a lie.
     *
     * Undo is driven off shopify_media_mints — append-only, never nulled.
     *
     * Delete the ShopifyMediaMint::record() call in createMedia() (or drive undo off pushedAssets)
     * -> RED (the orphan is still in the gallery after the restore).
     */
    public function test_undo_removes_every_media_we_ever_minted_not_just_the_last(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        // 1. A destructive push whose media Shopify processes to FAILED: minted, LIVE, and unlinked.
        $this->processingFails = true;
        $this->runPush($shop, $asset, MediaPlacement::position(1));

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertNull($asset->shopify_media_id, 'The dead media id is dropped — the media object is not.');

        // The orphan is read from the STORE, not from any bookkeeping of ours: it is simply the
        // media that appeared in the merchant's gallery and that nothing of ours points at now.
        $orphan = array_values(array_diff($this->galleryIds(), $originals))[0];

        // 2. The re-push succeeds and the asset now points at a DIFFERENT media.
        $this->processingFails = false;
        $this->runPush($shop, $asset->refresh(), MediaPlacement::position(1));

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertNotSame($orphan, (string) $asset->shopify_media_id, 'The asset forgot the first media.');

        // 3. UNDO — "restore my original images" must MEAN it.
        $this->runUndo($shop);

        $this->assertNotContains($orphan, $this->galleryIds(), 'Our ORPHANED AI image is still live in the merchant storefront after an undo.');
        $this->assertSame($originals, $this->galleryIds(), 'The merchant must be left with exactly their originals.');

        // ...and the ledger that made it possible remembers BOTH media we ever put in their store.
        $this->assertCount(2, $this->mintedIds($shop));
        $this->assertSame(ProductAsset::PUSH_NOT_PUSHED, $asset->refresh()->push_status);
    }

    /**
     * S8 — the snapshot exclusion inherited the same amnesia. It excluded "our own media" by reading
     * the mutable pointer, so a media of ours whose link had been dropped was captured as a merchant
     * ORIGINAL — and a later undo would RE-UPLOAD our AI image into the live storefront (the B2 scar,
     * through a side door). The exclusion reads the mint ledger.
     *
     * Point ourMediaIds() back at ProductAsset::shopify_media_id -> RED.
     */
    public function test_our_media_is_excluded_from_a_snapshot_even_when_its_asset_link_was_dropped(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $appended = $this->approvedAsset($shop);
        $replacing = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        // An APPEND is not destructive: no snapshot yet, and our image is now in the live gallery.
        $this->runPush($shop, $appended, MediaPlacement::append());
        $appendedId = (string) $appended->refresh()->shopify_media_id;

        // The pointer is dropped — exactly what undo, a dead media and a reclaim all do to it. The
        // media itself is still very much in the merchant's store.
        Tenant::run($shop['account'], fn () => $appended->forceFill(['shopify_media_id' => null])->save());

        $this->assertContains($appendedId, $this->galleryIds());

        // NOW the first destructive push takes the snapshot.
        $this->runPush($shop, $replacing, MediaPlacement::replace($originals[0]));

        $snapshotIds = array_map(
            static fn (array $e): string => (string) $e[ShopifyMediaSnapshot::ENTRY_MEDIA_ID],
            $this->snapshotOf($shop)->entries(),
        );

        $this->assertNotContains($appendedId, $snapshotIds, 'Our own image is not a merchant "original".');
        $this->assertSame($originals, $snapshotIds);
    }

    // ---------------------------------------------------------------------------------------
    // S9 / S10 — TRUST THE ANSWER, NOT THE CALL. AND CLOSE EVERY ROW THAT IS IN THE STORE.
    // ---------------------------------------------------------------------------------------

    /**
     * S9 — productDeleteMedia answers with `deletedMediaIds`. An id that is NOT in that list was NOT
     * deleted (200, no mediaUserErrors — the missing id is the only signal). Clearing the asset link
     * on the strength of the CALL left our image live in the storefront AND unlinked: an orphan, and
     * the panel telling the merchant it was gone.
     *
     * Delete assertDeleted() -> RED (the link is cleared while the image is still in the gallery).
     */
    public function test_a_delete_shopify_does_not_confirm_never_clears_the_asset_link(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $first = $this->galleryIds()[0];

        $this->runPush($shop, $asset, MediaPlacement::replace($first));

        $ourId = (string) $asset->refresh()->shopify_media_id;

        // The store REPORTS the delete and quietly keeps the image.
        $this->deleteSilentlyKeeps = [$ourId];

        try {
            $this->runUndo($shop);
            $this->fail('An unconfirmed delete must never pass silently.');
        } catch (ShopifyMediaException $e) {
            $this->assertSame(ShopifyMediaException::CODE_DELETE_UNCONFIRMED, $e->errorCode);
        }

        $asset->refresh();

        // Our image is STILL in their store — so the row still says so, and a later undo retries it.
        $this->assertContains($ourId, $this->galleryIds());
        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertSame($ourId, (string) $asset->shopify_media_id);
    }

    /**
     * S10 — a push that dies AFTER productCreateMedia (here: the READY poll budget runs out) leaves
     * OUR MEDIA LIVE in the gallery with the asset at `push_failed`. Undo used to look only at
     * `pushed` rows, so it skipped that asset entirely: the image came out of the store (the mint
     * ledger sees it) but the row was left claiming a media id that no longer existed — and a later
     * re-push would "resume" a dead media forever.
     *
     * Remove PUSH_FAILED / PUSH_PUSHING from UndoProductMediaJob::RESET_STATUSES -> RED.
     */
    public function test_undo_closes_an_asset_whose_push_died_after_the_media_was_minted(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $anchor = $this->approvedAsset($shop);
        $stranded = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        // A destructive push first, so the product carries a snapshot (undo has something to replay).
        $this->runPush($shop, $anchor, MediaPlacement::position(1));

        // The second push mints its media and then dies waiting for Shopify to process it.
        $this->readyAfterPolls = 99;
        $this->runPush($shop, $stranded, MediaPlacement::append());

        $stranded->refresh();
        $strandedMedia = (string) $stranded->shopify_media_id;

        $this->assertSame(ProductAsset::PUSH_FAILED, $stranded->push_status);
        $this->assertNotSame('', $strandedMedia);
        $this->assertContains($strandedMedia, $this->galleryIds(), 'Our media is LIVE at push_failed.');

        // UNDO must take it out of the store AND close the row.
        $this->readyAfterPolls = 1;
        $this->runUndo($shop);

        $this->assertSame($originals, $this->galleryIds());
        $this->assertNotContains($strandedMedia, $this->galleryIds());

        $stranded->refresh();

        $this->assertSame(
            ProductAsset::PUSH_NOT_PUSHED,
            $stranded->push_status,
            'Undo took the image out of the store but left the row claiming it is still pushed.',
        );
        $this->assertNull($stranded->shopify_media_id, 'A row may never point at a media we just deleted.');
    }

    // ---------------------------------------------------------------------------------------
    // S7 — AN ORIGINAL THAT WAS UNDONE IS STILL AN ORIGINAL.
    // ---------------------------------------------------------------------------------------

    /**
     * After an undo, a re-uploaded original lives under a NEW media id (Shopify mints a new object
     * for our bytes). assertMediaRestorable() matched only the ORIGINAL id, so every later replace
     * on that product was refused with "it was never backed up" — a lie: we hold its bytes, which is
     * exactly why we just put them back. The destructive rail was dead for every undone product.
     *
     * Drop the ENTRY_RESTORED_MEDIA_ID match from entryIs() -> RED (the second replace is refused).
     */
    public function test_an_original_that_was_restored_can_be_replaced_again(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $first = $this->approvedAsset($shop);
        $second = $this->approvedAsset($shop);
        $original = $this->galleryIds()[0];

        $this->runPush($shop, $first, MediaPlacement::replace($original));
        $this->assertNotContains($original, $this->galleryIds());

        $this->runUndo($shop);

        // The original is back — under a NEW id.
        $restored = (string) ($this->snapshotOf($shop)->entries()[0][ShopifyMediaSnapshot::ENTRY_RESTORED_MEDIA_ID] ?? '');

        $this->assertNotSame('', $restored);
        $this->assertContains($restored, $this->galleryIds());

        // The merchant changes their mind and replaces that same original again. We hold its bytes;
        // the push must run.
        $this->runPush($shop, $second, MediaPlacement::replace($restored));

        $second->refresh();

        $this->assertSame(
            ProductAsset::PUSH_PUSHED,
            $second->push_status,
            'The destructive rail is dead for every product that was ever undone: '.(string) $second->push_error,
        );
        $this->assertNull($second->push_error);
        $this->assertNotContains($restored, $this->galleryIds());
        $this->assertContains((string) $second->shopify_media_id, $this->galleryIds());
    }

    // ---------------------------------------------------------------------------------------
    // S12 — THE ONE RAIL THAT MUST NOT THROTTLE DOES NOT RE-READ THE WHOLE GALLERY 20 TIMES.
    // ---------------------------------------------------------------------------------------

    /**
     * The READY poll ran up to 20 attempts, and each attempt walked the ENTIRE paginated gallery:
     * up to 200 cost-weighted GraphQL calls per media on a large product. A throttle here parks the
     * push and makes the merchant wait another 30 seconds — on the rail that mutates a live store.
     *
     * It polls ONE node.
     *
     * Point awaitReady() back at find() (the gallery walk) -> RED (0 node reads, N gallery walks).
     */
    public function test_the_ready_poll_asks_for_one_node_and_never_walks_the_gallery(): void
    {
        config()->set(self::CFG_PER_PRODUCT, 1); // every gallery read is a multi-page walk

        $shop = $this->mediaShop(originals: 3);
        $asset = $this->approvedAsset($shop);

        $this->readyAfterPolls = self::SLOW_PROCESSING_POLLS; // the poll really has to spin

        $this->runPush($shop, $asset, MediaPlacement::append());

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->refresh()->push_status);

        // Five targeted reads of the ONE media we are waiting on — and not one gallery walk.
        $this->assertSame(self::SLOW_PROCESSING_POLLS, $this->graphqlCalls('TrayOnMediaNode'));
        $this->assertSame(0, $this->graphqlCalls('TrayOnProductMedia'));
    }

    /** Every media id we ever minted on this product (the append-only ledger). */
    private function mintedIds(array $shop): array
    {
        return Tenant::run(
            $shop['account'],
            fn (): array => ShopifyMediaMint::mediaIdsForProduct((int) $shop['product']->getKey()),
        );
    }
}
