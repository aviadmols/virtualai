<?php

namespace Tests\Feature\Shopify;

use App\Domain\ProductImages\SubmitProductImageJob;
use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushProductMediaJob;
use App\Domain\Shopify\Media\PushResult;
use App\Domain\Shopify\Media\UndoProductMediaJob;
use App\Models\ProductAsset;
use App\Models\ShopifyMediaSnapshot;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * TENANT SAFETY on the store rail — a release blocker.
 *
 * Two accounts, two connected shops, two products, two approved images. Account B must not be
 * able to push, re-push or undo ANYTHING of account A's — not through the entry point, not
 * through a job dispatched with the wrong account, and not by reading A's snapshot.
 *
 * The back-to-back worker case is the one that has bitten this project before (TS-TENANCY-001):
 * a job for A followed by a job for B on the SAME worker must never leak A's bound tenant.
 */
class ShopifyMediaIsolationTest extends TestCase
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

    public function test_account_b_cannot_push_account_a_s_image(): void
    {
        // Both shops FIRST: mediaShop() re-seeds the fake store's gallery, so building B after a
        // push by A would rewrite the store under A's feet.
        $a = $this->mediaShop(originals: 1);
        $b = $this->mediaShop(originals: 1, shopDomain: self::MEDIA_SHOP_B);
        $assetA = $this->approvedAsset($a);

        // B asks to push A's asset id, inside B's own tenant. The global scope fails CLOSED:
        // the row is simply not there, so there is nothing to push.
        $result = Tenant::run($b['account'], fn (): PushResult => app(PushProductMedia::class)
            ->push($b['site'], (int) $assetA->getKey(), MediaPlacement::append()));

        $this->assertTrue($result->wasDenied());
        $this->assertSame(PushResult::REASON_NOT_FOUND, $result->deniedReason);

        Bus::assertNotDispatched(PushProductMediaJob::class);
        $this->assertSame(0, $this->createdMediaCount());
        $this->assertSame(ProductAsset::PUSH_NOT_PUSHED, $assetA->refresh()->push_status);
    }

    public function test_a_push_job_bound_to_the_wrong_account_touches_nothing(): void
    {
        $a = $this->mediaShop(originals: 1);
        $b = $this->mediaShop(originals: 1, shopDomain: self::MEDIA_SHOP_B);
        $assetA = $this->approvedAsset($a);

        // A job for account B carrying A's asset id: findOrFail runs under B's global scope and
        // resolves NOTHING. The store is never called; A's asset is untouched.
        $job = new PushProductMediaJob(
            (int) $b['account']->getKey(),
            (int) $b['site']->getKey(),
            (int) $assetA->getKey(),
            MediaPlacement::append()->toArray(),
        );

        try {
            $job->handle();
            $this->fail('A cross-account push must not resolve the asset.');
        } catch (ModelNotFoundException) {
            // fail closed — exactly what we want
        }

        $this->assertSame(0, $this->createdMediaCount());
        $this->assertSame(ProductAsset::PUSH_NOT_PUSHED, $assetA->refresh()->push_status);
    }

    public function test_account_b_cannot_undo_account_a_s_product(): void
    {
        $a = $this->mediaShop(originals: 2);
        $b = $this->mediaShop(originals: 2, shopDomain: self::MEDIA_SHOP_B);
        $assetA = $this->approvedAsset($a);

        // A does a destructive push, so A now has a captured snapshot.
        $this->runPush($a, $assetA, MediaPlacement::replace($this->galleryIds()[0]));
        $galleryAfterA = $this->galleryIds();

        $result = Tenant::run($b['account'], fn (): PushResult => app(PushProductMedia::class)
            ->undo($b['site'], (int) $a['product']->getKey()));

        $this->assertTrue($result->wasDenied());
        $this->assertSame(PushResult::REASON_NOT_FOUND, $result->deniedReason);
        Bus::assertNotDispatched(UndoProductMediaJob::class);

        // B also cannot even SEE A's snapshot.
        $this->assertSame(0, Tenant::run($b['account'], fn (): int => ShopifyMediaSnapshot::query()->count()));
        $this->assertSame(1, Tenant::run($a['account'], fn (): int => ShopifyMediaSnapshot::query()->count()));

        // A's store is exactly as A left it.
        $this->assertSame($galleryAfterA, $this->galleryIds());
    }

    /** TS-TENANCY-001: a worker must never leak one job's tenant into the next. */
    public function test_two_accounts_push_back_to_back_on_one_worker_without_leaking(): void
    {
        $a = $this->mediaShop(originals: 1);
        $b = $this->mediaShop(originals: 1, shopDomain: self::MEDIA_SHOP_B);
        $assetA = $this->approvedAsset($a);
        $assetB = $this->approvedAsset($b);

        $this->runPush($a, $assetA, MediaPlacement::append());
        $this->assertFalse(Tenant::check(), 'The worker must not stay bound to account A.');

        $this->runPush($b, $assetB, MediaPlacement::append());
        $this->assertFalse(Tenant::check(), 'The worker must not stay bound to account B.');

        $assetA->refresh();
        $assetB->refresh();

        $this->assertSame(ProductAsset::PUSH_PUSHED, $assetA->push_status);
        $this->assertSame(ProductAsset::PUSH_PUSHED, $assetB->push_status);
        $this->assertNotSame($assetA->shopify_media_id, $assetB->shopify_media_id);

        // Each asset is owned by its OWN account — nothing crossed.
        $this->assertSame((int) $a['account']->getKey(), (int) $assetA->account_id);
        $this->assertSame((int) $b['account']->getKey(), (int) $assetB->account_id);
    }

    public function test_the_snapshot_model_is_tenant_scoped_and_not_on_the_global_allow_list(): void
    {
        $a = $this->mediaShop(originals: 1);
        $b = $this->mediaShop(originals: 1, shopDomain: self::MEDIA_SHOP_B);
        $assetA = $this->approvedAsset($a);

        $this->runPush($a, $assetA, MediaPlacement::position(1));

        $this->assertFalse(GlobalModels::isGlobal(ShopifyMediaSnapshot::class));

        // Fail closed: unbound, the snapshot is invisible.
        Tenant::clear();
        $this->assertSame(0, ShopifyMediaSnapshot::query()->count());

        // Bound to B, still invisible; bound to A, exactly one.
        $this->assertSame(0, Tenant::run($b['account'], fn (): int => ShopifyMediaSnapshot::query()->count()));
        $this->assertSame(1, Tenant::run($a['account'], fn (): int => ShopifyMediaSnapshot::query()->count()));
    }

    /**
     * PHASE-4 SUGGESTION 14 (closed here): SubmitProductImageJob's ShouldBeUnique was UNPINNED —
     * removing the interface left the suite green. It is the only wall against a double PROVIDER
     * RENDER, which we pay fal for out of pocket. Pinned the way both Shopify sync jobs are.
     */
    public function test_the_product_image_submit_job_is_should_be_unique_with_an_expiring_lock(): void
    {
        $job = new SubmitProductImageJob(1, 2, 3);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertGreaterThan(0, $job->uniqueFor);
    }
}
