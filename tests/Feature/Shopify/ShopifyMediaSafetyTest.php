<?php

namespace Tests\Feature\Shopify;

use App\Domain\Media\MediaStorage;
use App\Domain\Media\MediaWriteException;
use App\Domain\ProductImages\ProductImageReview;
use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushProductMediaJob;
use App\Domain\Shopify\Media\PushResult;
use App\Domain\Shopify\Media\UndoProductMediaJob;
use App\Models\ProductAsset;
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
}
