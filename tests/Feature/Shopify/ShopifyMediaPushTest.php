<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushProductMediaJob;
use App\Domain\Shopify\Media\PushResult;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\ShopifyMediaSnapshot;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 5 — pushing an APPROVED image into the store's product media, with FULL placement
 * control (append / position N / replace an existing image).
 *
 * Every law this suite pins, in the order the pipeline enforces them:
 *   a destructive push SNAPSHOTS the originals first, and FAILS CLOSED if it cannot ·
 *   a replaced image is deleted ONLY after its replacement is confirmed READY ·
 *   a double-clicked push creates EXACTLY ONE Shopify media ·
 *   a re-push retries the PUSH ONLY — never the AI, never a charge ·
 *   pushing is FREE: no reservation, no ledger row, ever ·
 *   a throttle PARKS (with its park index in uniqueId), it is not a failure.
 *
 * Bus::fake() is mandatory: PushProductMediaJob re-dispatches ITSELF on a park, and the sync
 * queue driver would otherwise cascade the whole chain inside one handle() call, destroying the
 * property the park test exists to prove (TS-BUILD-007). Every job is driven by hand.
 */
class ShopifyMediaPushTest extends TestCase
{
    use RefreshDatabase, ShopifyMediaTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootShopifyMediaEnv();
        $this->fakeShopifyStore();
        Bus::fake([PushProductMediaJob::class]);
    }

    /** Run the push job by hand, with the tenant bound the way the worker would. */
    private function runPush(array $shop, ProductAsset $asset, MediaPlacement $placement, int $parks = 0, ?string $claimId = null): void
    {
        (new PushProductMediaJob(
            (int) $shop['account']->getKey(),
            (int) $shop['site']->getKey(),
            (int) $asset->getKey(),
            $placement->toArray(),
            $parks,
            $claimId,
        ))->handle();
    }

    // --- APPEND: the safe default. Nothing existing is touched, nothing is snapshotted. ---

    public function test_append_adds_the_image_at_the_end_and_never_touches_the_originals(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::append());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertNotNull($asset->shopify_media_id);
        $this->assertNotNull($asset->pushed_at);
        $this->assertNull($asset->push_error);

        // The new image is LAST; both originals kept their slots, featured image untouched.
        $this->assertSame([...$originals, $asset->shopify_media_id], $this->galleryIds());
        $this->assertSame($originals[0], $this->featuredId());

        // An append destroys nothing -> no snapshot is needed, and none is taken.
        $this->assertSame(0, ShopifyMediaSnapshot::withoutGlobalScopes()->count());
        $this->assertNotContains('delete', $this->storeOps());
    }

    public function test_the_alt_text_is_templated_from_the_product_and_operation_with_strtr(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::append());

        $new = array_values(array_filter(
            $this->storeGallery,
            fn (array $m): bool => $m['id'] === $asset->refresh()->shopify_media_id,
        ))[0];

        $this->assertSame('Merino Crew — Clean packshot', $new['alt']);
    }

    // --- POSITION N: destructive (it moves the featured image), so it MUST snapshot first. ---

    public function test_position_one_makes_the_image_the_main_image_and_snapshots_the_originals_first(): void
    {
        $shop = $this->mediaShop(originals: 3);
        $asset = $this->approvedAsset($shop);
        $originals = $this->galleryIds();

        $this->runPush($shop, $asset, MediaPlacement::position(1));

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertSame(ProductAsset::PLACEMENT_POSITION, $asset->push_placement);
        $this->assertSame(1, $asset->push_position);

        // Slot 1 is ours; every original survives, shifted down by one.
        $this->assertSame($asset->shopify_media_id, $this->featuredId());
        $this->assertSame([$asset->shopify_media_id, ...$originals], $this->galleryIds());
        $this->assertNotContains('delete', $this->storeOps());

        // THE SNAPSHOT: taken BEFORE the mutation, with OUR OWN copy of every original's bytes.
        $snapshot = Tenant::run($shop['account'], fn () => ShopifyMediaSnapshot::query()->first());

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isCaptured());
        $this->assertCount(3, $snapshot->entries());

        foreach ($snapshot->entries() as $entry) {
            Storage::disk('s3')->assertExists($entry[ShopifyMediaSnapshot::ENTRY_PATH]);
        }
    }

    // --- REPLACE: the most destructive placement. ---

    public function test_replace_swaps_the_image_and_deletes_the_old_one_only_after_the_new_one_is_ready(): void
    {
        $shop = $this->mediaShop(originals: 3);
        $asset = $this->approvedAsset($shop);
        [$first, $second, $third] = $this->galleryIds();

        // Shopify keeps the new media UPLOADED for two reads before it turns READY.
        $this->readyAfterPolls = 2;

        $this->runPush($shop, $asset, MediaPlacement::replace($second));

        $asset->refresh();
        $newId = (string) $asset->shopify_media_id;

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertSame(ProductAsset::PLACEMENT_REPLACE, $asset->push_placement);
        $this->assertSame($second, $asset->push_replaced_media_id);

        // The new image took the replaced one's SLOT; the replaced one is gone.
        $this->assertSame([$first, $newId, $third], $this->galleryIds());

        // THE ORDER-OF-OPERATIONS LAW: create -> reorder -> delete. The delete is LAST.
        $this->assertSame(['create', 'reorder', 'delete'], $this->storeOps());

        // THE READY LAW: at the instant the old image was deleted, the replacement was already
        // in the gallery AND already READY. A delete-before-ready would blank the storefront slot.
        $delete = $this->deleteEntry();

        $this->assertNotNull($delete);
        $this->assertSame([$second], $delete['ids']);
        $this->assertContains($newId, $delete['gallery']);
        $this->assertSame('READY', $delete['statuses'][$newId]);
    }

    public function test_a_destructive_push_fails_closed_when_the_originals_cannot_be_backed_up(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);
        $before = $this->galleryIds();

        // Shopify's CDN will not give us the original bytes -> we cannot honour an undo.
        $this->originalDownloadBroken = true;

        $this->runPush($shop, $asset, MediaPlacement::replace($before[0]));

        $asset->refresh();

        // The push is REFUSED. Nothing was uploaded, nothing was reordered, nothing was deleted.
        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertNull($asset->shopify_media_id);
        $this->assertStringContainsString('back up the original images', (string) $asset->push_error);
        $this->assertSame($before, $this->galleryIds());
        $this->assertSame([], $this->storeOps());
        $this->assertSame(0, $this->createdMediaCount());

        $snapshot = Tenant::run($shop['account'], fn () => ShopifyMediaSnapshot::query()->first());
        $this->assertSame(ShopifyMediaSnapshot::STATUS_FAILED, $snapshot->status);
    }

    // --- IDEMPOTENCY: exactly ONE Shopify media, whatever the merchant clicks. ---

    public function test_a_double_clicked_push_creates_exactly_one_shopify_media(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        // Two dispatches of the SAME click reach the worker (ShouldBeUnique dropped in tests).
        $this->runPush($shop, $asset, MediaPlacement::append());
        $this->runPush($shop, $asset, MediaPlacement::append());

        $this->assertSame(1, $this->createdMediaCount());
        $this->assertCount(2, $this->storeGallery); // 1 original + 1 pushed image, not 2 pushed
        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->refresh()->push_status);
    }

    public function test_the_push_job_is_should_be_unique_and_its_lock_expires(): void
    {
        $job = new PushProductMediaJob(1, 2, 3, MediaPlacement::append()->toArray());

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertGreaterThan(0, $job->uniqueFor);

        // A double-clicked push collapses onto ONE lock...
        $this->assertSame(
            $job->uniqueId(),
            (new PushProductMediaJob(1, 2, 3, MediaPlacement::position(1)->toArray()))->uniqueId(),
        );

        // ...but a PARKED retry must NOT be swallowed by the lock its predecessor still holds.
        $this->assertNotSame(
            $job->uniqueId(),
            (new PushProductMediaJob(1, 2, 3, MediaPlacement::append()->toArray(), 1))->uniqueId(),
        );
    }

    /**
     * THE RESUME WALL. A push can fail AFTER Shopify has already minted the media (the READY poll
     * budget runs out, the worker dies, the store throttles us into the ground). The media id is
     * persisted the instant Shopify hands it back, so a RE-PUSH must resume that media — never
     * upload a second copy. Without this wall the merchant's gallery grows a duplicate on every
     * retry, and only the LAST one is ever tracked; the rest are orphans we can no longer clean up.
     */
    public function test_a_re_push_after_a_failure_that_already_minted_the_media_never_uploads_a_second_copy(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        // Shopify accepts the media but never finishes processing it -> the READY budget is spent.
        $this->readyAfterPolls = 99;
        $this->runPush($shop, $asset, MediaPlacement::append());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertStringContainsString('did not finish processing', (string) $asset->push_error);

        // The media EXISTS on the store and we know its id — that is the whole point.
        $mediaId = (string) $asset->shopify_media_id;
        $this->assertNotSame('', $mediaId);
        $this->assertSame(1, $this->createdMediaCount());
        $this->assertContains($mediaId, $this->galleryIds());

        // Shopify finishes processing; the merchant re-pushes.
        $this->readyAfterPolls = 1;
        $this->runPush($shop, $asset->refresh(), MediaPlacement::append());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertSame($mediaId, $asset->shopify_media_id, 'The re-push must RESUME the same media.');
        $this->assertSame(1, $this->createdMediaCount(), 'A re-push must never upload a second copy.');
        $this->assertCount(2, $this->storeGallery); // 1 original + 1 pushed image
    }

    public function test_an_asset_already_in_the_store_is_never_uploaded_again(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::append());

        $result = Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
            ->push($shop['site'], (int) $asset->getKey(), MediaPlacement::append()));

        $this->assertTrue($result->wasDenied());
        $this->assertSame(PushResult::REASON_ALREADY_PUSHED, $result->deniedReason);
        $this->assertSame(1, $this->createdMediaCount());
    }

    // --- RE-PUSH: retries the PUSH only. Never the AI. Never a charge. ---

    public function test_a_re_push_retries_the_upload_only_and_never_regenerates_or_charges(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        // Shopify refuses the media the first time (a real mediaUserError).
        $this->createMediaUserError = 'Image must be smaller than 20 MB';
        $this->runPush($shop, $asset, MediaPlacement::append());

        $asset->refresh();
        $this->assertSame(ProductAsset::PUSH_FAILED, $asset->push_status);
        $this->assertSame('Image must be smaller than 20 MB', $asset->push_error); // verbatim
        $this->assertSame(0, $this->createdMediaCount());

        $chargesBefore = CreditLedger::withoutGlobalScopes()->count();
        $statusBefore = $asset->status;

        // The merchant retries. The store accepts it now.
        $this->createMediaUserError = null;

        $result = Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
            ->rePush($shop['site'], (int) $asset->getKey()));

        $this->assertTrue($result->queued);
        Bus::assertDispatched(PushProductMediaJob::class);

        $this->runPush($shop, $asset->refresh(), MediaPlacement::append());

        $asset->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->push_status);
        $this->assertSame(1, $this->createdMediaCount());
        $this->assertNull($asset->push_error);

        // NOTHING about the GENERATION moved: no new render, no new asset, no new charge row.
        $this->assertSame($statusBefore, $asset->status);
        $this->assertSame($chargesBefore, CreditLedger::withoutGlobalScopes()->count());
        $this->assertSame(1, ProductAsset::withoutGlobalScopes()->count());
    }

    /** A push is FREE — pushing, re-pushing and undoing never touch the credit ledger. */
    public function test_a_push_never_writes_a_ledger_row_and_never_reserves(): void
    {
        $shop = $this->mediaShop(originals: 2);
        $asset = $this->approvedAsset($shop);

        $ledgerBefore = CreditLedger::withoutGlobalScopes()->count();
        $balanceBefore = (int) $shop['account']->fresh()->balance_micro_usd;

        $this->runPush($shop, $asset, MediaPlacement::position(1));

        $account = Account::query()->find($shop['account']->getKey());

        $this->assertSame($ledgerBefore, CreditLedger::withoutGlobalScopes()->count());
        $this->assertSame($balanceBefore, (int) $account->balance_micro_usd);
        $this->assertSame(0, (int) $account->reserved_micro_usd);
    }

    // --- THE GATES: only an APPROVED image of a SHOPIFY product goes to a live storefront. ---

    public function test_an_unapproved_image_is_refused_with_a_typed_result_never_an_error(): void
    {
        $shop = $this->mediaShop(originals: 1);

        $asset = Tenant::run($shop['account'], function () use ($shop): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($shop['site'])->create();

            return ProductAsset::factory()->forProduct($shop['product'], $batch)->succeeded()->create();
        });

        $result = Tenant::run($shop['account'], fn (): PushResult => app(PushProductMedia::class)
            ->push($shop['site'], (int) $asset->getKey(), MediaPlacement::append()));

        $this->assertTrue($result->wasDenied());
        $this->assertSame(PushResult::REASON_NOT_APPROVED, $result->deniedReason);
        Bus::assertNotDispatched(PushProductMediaJob::class);

        // And the model itself refuses the transition — the wall is not only in the entry point.
        $this->expectException(RuntimeException::class);
        Tenant::run($shop['account'], fn () => $asset->pushTransitionTo(ProductAsset::PUSH_PUSHING));
    }

    public function test_an_illegal_push_transition_is_rejected(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->expectException(RuntimeException::class);

        // not_pushed -> pushed is not a legal edge: an image cannot be in the store without ever
        // having been uploaded to it.
        Tenant::run($shop['account'], fn () => $asset->pushTransitionTo(ProductAsset::PUSH_PUSHED));
    }

    public function test_a_push_writes_the_activity_trail(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        $this->runPush($shop, $asset, MediaPlacement::append());

        $kinds = ActivityEvent::withoutGlobalScopes()->pluck('kind')->all();

        $this->assertContains(ActivityEvent::KIND_PRODUCT_ASSET_PUSH_STATUS_CHANGED, $kinds);
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_MEDIA_PUSHED, $kinds);
    }

    // --- THROTTLE: a park is not a failure (the Phase-3 precedent). ---

    public function test_a_throttled_push_parks_and_redispatches_with_its_park_index(): void
    {
        $shop = $this->mediaShop(originals: 1);
        $asset = $this->approvedAsset($shop);

        // The store rate-limits us; the client's retry budget is spent -> a typed throttle.
        config()->set('shopify.throttle.max_retries', 0);
        $this->throttleNext = true;

        $this->runPush($shop, $asset, MediaPlacement::append());

        // The asset is NOT failed — the push is still in flight, and a fresh job carries it on.
        $this->assertSame(ProductAsset::PUSH_PUSHING, $asset->refresh()->push_status);
        $this->assertNull($asset->push_error);

        // THE CLAIM RIDES WITH THE PARK. The continuation is the same push, so park() dispatches
        // it carrying the lease — without it, the continuation would look like a stranger to its
        // own claim and be refused (the B7 lease law).
        $claim = null;

        Bus::assertDispatched(
            PushProductMediaJob::class,
            function (PushProductMediaJob $job) use ($asset, &$claim): bool {
                if ($job->parks === 1 && $job->productAssetId === (int) $asset->getKey()) {
                    $claim = $job->claimId;

                    return true;
                }

                return false;
            },
        );

        $this->assertNotNull($claim, 'A parked continuation must carry its own push lease.');

        // The parked continuation resumes the SAME asset and finishes it — one media, not two.
        $this->runPush($shop, $asset->refresh(), MediaPlacement::append(), parks: 1, claimId: $claim);

        $this->assertSame(ProductAsset::PUSH_PUSHED, $asset->refresh()->push_status);
        $this->assertSame(1, $this->createdMediaCount());
    }
}
