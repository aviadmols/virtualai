<?php

namespace Tests\Feature\ProductImages;

use App\Domain\ProductImages\ProductImageReview;
use App\Models\ActivityEvent;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The REVIEW machine — the merchant's judgement on a generated image.
 *
 * It is editorial, never financial: a rejection does NOT reverse the charge (the AI ran and the
 * provider billed us). It is also guarded: an asset that never produced an image cannot be
 * approved or rejected, and every accepted move writes an activity event.
 */
class ProductImageReviewTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
    }

    /** @return array{0: array, 1: ProductAsset} */
    private function makeSucceededAsset(): array
    {
        $shop = $this->makeShop();

        $asset = Tenant::run($shop['account'], function () use ($shop): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($shop['site'])->running()->create();

            return ProductAsset::factory()
                ->forProduct($shop['product'], $batch)
                ->succeeded()
                ->create(['charge_micro_usd' => 97_500]);
        });

        return [$shop, $asset];
    }

    public function test_approve_and_reject_are_guarded_moves_that_leave_a_trace(): void
    {
        [$shop, $asset] = $this->makeSucceededAsset();

        Tenant::run($shop['account'], function () use ($shop, $asset): void {
            $review = app(ProductImageReview::class);

            $this->assertTrue($review->approve($shop['site'], (int) $asset->getKey()));
            $this->assertSame(ProductAsset::REVIEW_APPROVED, $asset->fresh()->review_status);

            // A merchant may change their mind: approved -> rejected is a legal move.
            $this->assertTrue($review->reject($shop['site'], (int) $asset->getKey()));
            $this->assertSame(ProductAsset::REVIEW_REJECTED, $asset->fresh()->review_status);

            $kinds = ActivityEvent::query()->pluck('kind')->all();
            $this->assertContains(ActivityEvent::KIND_PRODUCT_ASSET_APPROVED, $kinds);
            $this->assertContains(ActivityEvent::KIND_PRODUCT_ASSET_REJECTED, $kinds);
        });
    }

    /** A rejection is NOT a refund: the charge row and the balance are untouched. */
    public function test_rejecting_an_image_never_refunds_the_generation(): void
    {
        [$shop, $asset] = $this->makeSucceededAsset();

        $balanceBefore = $shop['account']->fresh()->balance_micro_usd;

        Tenant::run($shop['account'], fn () => app(ProductImageReview::class)->reject($shop['site'], (int) $asset->getKey()));

        $this->assertSame(ProductAsset::REVIEW_REJECTED, $asset->fresh()->review_status);
        $this->assertSame($balanceBefore, $shop['account']->fresh()->balance_micro_usd);
        $this->assertSame(97_500, (int) $asset->fresh()->charge_micro_usd, 'The charge stands — the AI already ran.');
    }

    /**
     * Delete is editorial cleanup, not a refund — and it is tenant-safe: account B can never
     * delete account A's image (fail closed), while the owner removes it for good.
     */
    public function test_delete_removes_a_finished_image_and_never_a_foreign_one(): void
    {
        [$shopA, $assetA] = $this->makeSucceededAsset();
        [$shopB] = $this->makeSucceededAsset();

        $review = app(ProductImageReview::class);
        $idA = (int) $assetA->getKey();

        // Account B cannot delete account A's image — it stays.
        Tenant::run($shopB['account'], fn () => $this->assertFalse($review->delete($shopA['site'], $idA)));
        Tenant::run($shopA['account'], fn () => $this->assertNotNull(ProductAsset::query()->find($idA)));

        // Its owner deletes it for good — the row is gone.
        Tenant::run($shopA['account'], function () use ($review, $shopA, $idA): void {
            $this->assertTrue($review->delete($shopA['site'], $idA));
            $this->assertNull(ProductAsset::query()->find($idA));
        });
    }

    /** An asset that never produced an image cannot be judged (the guard throws). */
    public function test_a_failed_asset_cannot_be_reviewed(): void
    {
        $shop = $this->makeShop();

        $asset = Tenant::run($shop['account'], function () use ($shop): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($shop['site'])->running()->create();

            return ProductAsset::factory()
                ->forProduct($shop['product'], $batch)
                ->create(['status' => ProductAsset::STATUS_FAILED]);
        });

        Tenant::run($shop['account'], function () use ($shop, $asset): void {
            // The service refuses quietly (nothing to review) ...
            $this->assertFalse(app(ProductImageReview::class)->approve($shop['site'], (int) $asset->getKey()));

            // ... and the model's guard is loud if anyone tries to force it.
            $this->expectException(RuntimeException::class);
            $asset->reviewTransitionTo(ProductAsset::REVIEW_APPROVED);
        });
    }

    public function test_bulk_approve_moves_every_awaiting_image(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop['account'], function () use ($shop): void {
            $batch = ProductImageBatch::factory()->forSite($shop['site'])->running(3)->create();

            ProductAsset::factory()->count(3)->forProduct($shop['product'], $batch)->succeeded()->create();

            $review = app(ProductImageReview::class);
            $this->assertSame(3, $review->counts($shop['site'])[ProductAsset::REVIEW_AWAITING]);

            $moved = $review->approveAwaiting($shop['site']);

            $this->assertSame(3, $moved);
            $this->assertSame(0, $review->counts($shop['site'])[ProductAsset::REVIEW_AWAITING]);
            $this->assertSame(3, $review->counts($shop['site'])[ProductAsset::REVIEW_APPROVED]);
        });
    }

    /**
     * TENANT ISOLATION on the review grid (release blocker). Account B can never read — let alone
     * approve — account A's images. The global scope fails CLOSED, so a forgotten filter returns
     * nothing rather than leaking.
     */
    public function test_the_review_grid_never_leaks_another_accounts_images(): void
    {
        [$shopA, $assetA] = $this->makeSucceededAsset();
        [$shopB, $assetB] = $this->makeSucceededAsset();

        $review = app(ProductImageReview::class);

        // A sees only A.
        Tenant::run($shopA['account'], function () use ($review, $shopA, $assetA): void {
            $ids = $review->grid($shopA['site'])->pluck('id')->all();
            $this->assertSame([(int) $assetA->getKey()], $ids);
            $this->assertSame(1, ProductAsset::query()->count());
        });

        // B sees only B — and cannot approve A's asset even by id.
        Tenant::run($shopB['account'], function () use ($review, $shopA, $shopB, $assetA, $assetB): void {
            $ids = $review->grid($shopB['site'])->pluck('id')->all();
            $this->assertSame([(int) $assetB->getKey()], $ids);

            $this->assertFalse(
                $review->approve($shopB['site'], (int) $assetA->getKey()),
                'A foreign asset id must not be reviewable.',
            );

            // Even asking for A's SITE from inside B's tenant yields nothing (fail closed).
            $this->assertCount(0, $review->grid($shopA['site']));
        });

        $this->assertSame(ProductAsset::REVIEW_AWAITING, $assetA->fresh()->review_status);

        // With no tenant bound at all, the tenant model returns nothing.
        $this->assertSame(0, ProductAsset::query()->count());
    }
}
