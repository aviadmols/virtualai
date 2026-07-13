<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushProductMediaJob;
use App\Domain\Shopify\Media\PushResult;
use App\Domain\Shopify\Media\UndoProductMediaJob;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ShopifyMediaSnapshot;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 5 — UNDO: "restore my original images".
 *
 * The whole reason replace/reorder are allowed to ship. The laws pinned here:
 *   the originals come back — the BYTES, not just the order (Shopify drops them on delete) ·
 *   the original ORDER and the original FEATURED image are restored ·
 *   nothing unrecoverable is ever destroyed: an original is only ADDED back, and our own pushed
 *   media is removed only once every original is live and READY ·
 *   the assets return to not_pushed (their bytes are still ours -> pushable again, for free) ·
 *   the SNAPSHOT SURVIVES a restore, so a second undo is a clean no-op ·
 *   undo is FREE: it never touches the credit ledger.
 */
class ShopifyMediaUndoTest extends TestCase
{
    use RefreshDatabase, ShopifyMediaTestSupport;

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

    public function test_undo_restores_the_replaced_original_the_order_and_the_featured_image(): void
    {
        $shop = $this->mediaShop(originals: 3);
        $asset = $this->approvedAsset($shop);
        [$first, $second, $third] = $this->galleryIds();

        // The most destructive thing a merchant can do: replace the MAIN image.
        $this->runPush($shop, $asset, MediaPlacement::replace($first));

        $pushedId = (string) $asset->refresh()->shopify_media_id;

        $this->assertSame($pushedId, $this->featuredId());       // our image is now the main one
        $this->assertNotContains($first, $this->galleryIds());   // the original main image is GONE
        $this->assertSame([$pushedId, $second, $third], $this->galleryIds());

        // --- UNDO ---
        $this->runUndo($shop);

        $gallery = $this->galleryIds();

        // Three images again, in the ORIGINAL order, and the featured image is an ORIGINAL —
        // the first one is a RE-UPLOAD (Shopify deleted the old id, so it has a new one).
        $this->assertCount(3, $gallery);
        $this->assertSame([$second, $third], array_slice($gallery, 1));
        $this->assertNotContains($pushedId, $gallery);           // our image left the store

        $restoredMain = $gallery[0];
        $this->assertNotSame($second, $restoredMain);
        $this->assertNotSame($third, $restoredMain);
        $this->assertSame($restoredMain, $this->featuredId());

        // The restored main image carries the ORIGINAL alt text — it really is the original.
        $restored = array_values(array_filter($this->storeGallery, fn (array $m): bool => $m['id'] === $restoredMain))[0];
        $this->assertSame('original 1', $restored['alt']);

        // The asset is not_pushed again — its bytes are still ours, so it can be pushed again.
        $asset->refresh();
        $this->assertSame(ProductAsset::PUSH_NOT_PUSHED, $asset->push_status);
        $this->assertNull($asset->shopify_media_id);
        $this->assertNull($asset->pushed_at);
    }

    public function test_undo_never_deletes_our_media_before_every_original_is_back_and_ready(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        [$first, $second] = $this->galleryIds();

        $this->readyAfterPolls = 2; // Shopify takes its time processing the re-uploaded original
        $this->runPush($shop, $asset, MediaPlacement::replace($first));

        $pushedId = (string) $asset->refresh()->shopify_media_id;
        $this->storeLog = [];

        $this->runUndo($shop);

        // The ONLY safe order: put the original back (create), fix the order (reorder), and only
        // THEN remove ours (delete). A delete first would leave the shopper a gallery with a
        // missing image, and the original's bytes would be gone from Shopify forever.
        $this->assertSame(['create', 'reorder', 'delete'], $this->storeOps());

        $delete = $this->deleteEntry();

        $this->assertSame([$pushedId], $delete['ids']);
        $this->assertCount(3, $delete['gallery']); // both originals + ours, all still present

        // Every original in the gallery at the moment of the delete was READY.
        foreach ($delete['gallery'] as $id) {
            if ($id !== $pushedId) {
                $this->assertSame('READY', $delete['statuses'][$id]);
            }
        }
    }

    public function test_undo_is_idempotent_a_second_run_changes_nothing(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $second = $this->galleryIds()[1];

        $this->runPush($shop, $asset, MediaPlacement::replace($second));
        $this->runUndo($shop);

        $afterFirst = $this->galleryIds();
        $this->storeLog = [];

        $this->runUndo($shop);

        $this->assertSame($afterFirst, $this->galleryIds());
        $this->assertSame(0, $this->createdMediaCount());   // nothing re-uploaded a second time
        $this->assertNull($this->deleteEntry());            // nothing left of ours to delete

        // The SNAPSHOT SURVIVED both restores — that is what makes a second undo a no-op rather
        // than a second, emptier "restore" that would wipe the gallery.
        $snapshot = Tenant::run($shop['account'], fn () => ShopifyMediaSnapshot::query()->first());

        $this->assertTrue($snapshot->isCaptured());
        $this->assertCount(2, $snapshot->entries());
        $this->assertSame(2, (int) $snapshot->restore_count);
    }

    public function test_undo_is_free_and_never_touches_the_credit_ledger(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::replace($this->galleryIds()[0]));

        $ledgerBefore = CreditLedger::withoutGlobalScopes()->count();
        $balanceBefore = (int) $shop['account']->fresh()->balance_micro_usd;

        $this->runUndo($shop);

        $account = Account::query()->find($shop['account']->getKey());

        $this->assertSame($ledgerBefore, CreditLedger::withoutGlobalScopes()->count());
        $this->assertSame($balanceBefore, (int) $account->balance_micro_usd);
        $this->assertSame(0, (int) $account->reserved_micro_usd);
    }

    public function test_undo_writes_the_restore_trail(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::replace($this->galleryIds()[0]));
        $this->runUndo($shop);

        $kinds = ActivityEvent::withoutGlobalScopes()->pluck('kind')->all();

        $this->assertContains(ActivityEvent::KIND_SHOPIFY_MEDIA_SNAPSHOT_CAPTURED, $kinds);
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_MEDIA_RESTORED, $kinds);
    }

    public function test_a_product_that_was_never_touched_destructively_has_nothing_to_undo(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::append()); // non-destructive -> no snapshot

        $result = Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
            ->undo($shop['site'], (int) $shop['product']->getKey()));

        $this->assertTrue($result->wasDenied());
        $this->assertSame(PushResult::REASON_NOTHING_TO_UNDO, $result->deniedReason);
        Bus::assertNotDispatched(UndoProductMediaJob::class);
    }

    public function test_the_undo_job_is_should_be_unique_and_carries_its_park_index(): void
    {
        $job = new UndoProductMediaJob(1, 2, 3);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertGreaterThan(0, $job->uniqueFor);
        $this->assertSame($job->uniqueId(), (new UndoProductMediaJob(1, 2, 3))->uniqueId());
        $this->assertNotSame($job->uniqueId(), (new UndoProductMediaJob(1, 2, 3, 1))->uniqueId());
    }
}
