<?php

namespace Tests\Feature\ProductImages;

use App\Domain\ProductImages\PollProductImageJob;
use App\Domain\ProductImages\StartProductImageBatch;
use App\Domain\ProductImages\SubmitProductImageJob;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * TENANT ISOLATION for the bulk product-image pipeline (a release blocker).
 *
 * Two accounts' jobs run BACK-TO-BACK on one worker process — the exact shape that produced
 * TS-TENANCY-001 (a stale Tenant left bound between jobs charged the wrong account). Each job
 * carries its account_id EXPLICITLY and binds it via Tenant::run (self-clearing in `finally`),
 * so B's charge can only ever land on B.
 */
class ProductImageIsolationTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class, PollProductImageJob::class]);
    }

    /** @return array{0: array, 1: ProductAsset} */
    private function queueOne(): array
    {
        $shop = $this->makeShop();

        Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: ProductImageBatch::SOURCE_MAIN,
        ));

        $asset = Tenant::run($shop['account'], fn () => ProductAsset::query()
            ->where('site_id', $shop['site']->getKey())
            ->latest('id')
            ->firstOrFail());

        return [$shop, $asset];
    }

    private function runFull(array $shop, ProductAsset $asset): void
    {
        $accountId = (int) $shop['account']->getKey();
        $siteId = (int) $shop['site']->getKey();
        $assetId = (int) $asset->getKey();

        (new SubmitProductImageJob($accountId, $siteId, $assetId))->handle();
        (new PollProductImageJob($accountId, $siteId, $assetId))->handle();
    }

    public function test_two_accounts_run_back_to_back_on_one_worker_without_leaking(): void
    {
        [$shopA, $assetA] = $this->queueOne();
        [$shopB, $assetB] = $this->queueOne();

        // Back-to-back on the SAME process — no tenant is bound between them.
        $this->runFull($shopA, $assetA);
        $this->assertFalse(Tenant::check(), 'The worker must not leave a tenant bound between jobs.');

        $this->runFull($shopB, $assetB);
        $this->assertFalse(Tenant::check());

        foreach ([[$shopA, $assetA], [$shopB, $assetB]] as [$shop, $asset]) {
            $account = $shop['account'];

            // Each account was charged EXACTLY once, on its OWN ledger.
            $charges = Tenant::run($account, fn () => CreditLedger::query()
                ->where('type', CreditLedger::TYPE_CHARGE)
                ->get());

            $this->assertCount(1, $charges);
            $this->assertSame((int) $account->getKey(), (int) $charges->first()->account_id);
            $this->assertSame((int) $asset->getKey(), (int) $charges->first()->reference_id);
            $this->assertSame(CreditLedger::REFERENCE_PRODUCT_ASSET, $charges->first()->reference_type);

            $this->assertSame(5_000_000 - 97_500, $account->fresh()->balance_micro_usd);
            $this->assertSame(0, $account->fresh()->reserved_micro_usd);
            $this->assertSame(ProductAsset::STATUS_SUCCEEDED, $asset->fresh()->status);
        }

        // A can see only its own asset; B only its own (the global scope fails closed).
        $this->assertSame([(int) $assetA->getKey()], Tenant::run($shopA['account'], fn () => ProductAsset::query()->pluck('id')->all()));
        $this->assertSame([(int) $assetB->getKey()], Tenant::run($shopB['account'], fn () => ProductAsset::query()->pluck('id')->all()));
        $this->assertSame([], Tenant::run($shopA['account'], fn () => ProductAsset::query()->whereKey($assetB->getKey())->pluck('id')->all()));

        // And batches/ledgers are equally invisible across the boundary.
        $this->assertSame(1, Tenant::run($shopA['account'], fn () => ProductImageBatch::query()->count()));
        $this->assertSame(1, Tenant::run($shopB['account'], fn () => ProductImageBatch::query()->count()));
        $this->assertSame(0, ProductImageBatch::query()->count(), 'Unbound, a tenant model returns nothing.');
    }
}
