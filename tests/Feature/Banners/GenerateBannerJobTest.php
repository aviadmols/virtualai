<?php

namespace Tests\Feature\Banners;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Banners\BannerGenerationRequest;
use App\Domain\Banners\StartBannerGeneration;
use App\Domain\Credits\CreditMath;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Credits\ReservationManager;
use App\Domain\Generation\GenerateBannerJob;
use App\Domain\Generation\GenerationFailureCode;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\CreditLedger;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Generation\GenerationTestSupport;
use Tests\TestCase;

/**
 * The banner money path — a merchant-billed image generation. Mirrors GenerateTryOnJob's
 * laws minus the LeadGate (a banner is a merchant action; CreditGate is the only gate):
 * reserve -> generate (mocked) -> store PUBLIC result -> charge ONCE = round(cost × markup)
 * referencing the banner_asset -> release; release on failure with ZERO charge rows; a
 * double-dispatch collapses to one charge. Also proves the banner_generation operation
 * resolves from the DB seed and tenant isolation on banners/assets. OpenRouter HTTP + the
 * media disk are faked — no real calls.
 */
class GenerateBannerJobTest extends TestCase
{
    use GenerationTestSupport, RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGenerationEnv(); // seeds the AI control plane (incl. banner_generation) + fakes
    }

    /** @return array{account: Account, site: Site, banner: Banner} */
    private function makeBannerContext(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $banner = Tenant::run($account, fn () => Banner::factory()->forSite($site)->create());

        return compact('account', 'site', 'banner');
    }

    private function makePendingAsset(array $context, string $clientRequestId = 'crq-1'): BannerAsset
    {
        $banner = $context['banner'];

        $key = IdempotencyKey::forBanner(
            accountId: (int) $banner->account_id,
            siteId: (int) $banner->site_id,
            bannerId: (int) $banner->getKey(),
            clientRequestId: $clientRequestId,
        );

        return Tenant::run($context['account'], fn () => BannerAsset::factory()
            ->forBanner($banner, $clientRequestId)
            ->create(['idempotency_key' => $key, 'status' => BannerAsset::STATUS_PENDING]));
    }

    private function runJob(array $context, BannerAsset $asset): void
    {
        (new GenerateBannerJob(
            (int) $context['account']->id,
            (int) $context['site']->id,
            (int) $asset->id,
        ))->handle();
    }

    public function test_happy_path_reserves_generates_stores_public_and_charges_once(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40); // 0.40 × 2.5 = $1.00 = 1_000_000 micro
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);

        $this->runJob($context, $asset);

        $expectedCharge = CreditMath::chargeMicroUsd(0.40, 2.5);
        $this->assertSame(1_000_000, $expectedCharge);

        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_SUCCEEDED, $asset->status);
        $this->assertNotNull($asset->image_path);
        $this->assertNotNull($asset->charge_ledger_id);
        // The provider render time was measured + persisted for the timing report.
        $this->assertNotNull($asset->duration_ms);
        $this->assertGreaterThanOrEqual(0, $asset->duration_ms);
        $this->assertSame(CreditMath::usdToMicro(0.40), $asset->actual_cost_micro_usd);

        // Exactly ONE charge row, at the selling value, referencing the BANNER ASSET.
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->get());
        $this->assertCount(1, $charges);
        $this->assertSame(-$expectedCharge, $charges->first()->amount_micro_usd);
        $this->assertSame(CreditLedger::REFERENCE_BANNER_ASSET, $charges->first()->reference_type);
        $this->assertSame($asset->id, $charges->first()->reference_id);
        $this->assertSame($asset->charge_ledger_id, $charges->first()->id);

        // Result stored PUBLIC on the faked media disk under the banners path.
        Storage::disk('s3')->assertExists($asset->image_path);
        $this->assertStringContainsString('/banners/', $asset->image_path);
    }

    public function test_failure_path_releases_reservation_and_writes_no_charge(): void
    {
        $this->fakeOpenRouterOutage();
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);

        $this->runJob($context, $asset);

        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::AI_CALL_FAILED, $asset->failure_code);
        $this->assertNull($asset->charge_ledger_id);

        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);
    }

    public function test_cost_unavailable_is_treated_as_a_failure_no_charge(): void
    {
        $this->fakeOpenRouterNoCost();
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);

        $this->runJob($context, $asset);

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::COST_UNAVAILABLE, $asset->failure_code);
        $this->assertNull($asset->charge_ledger_id);

        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);
    }

    public function test_double_dispatch_same_asset_charges_once(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);

        $this->runJob($context, $asset);
        $this->runJob($context, $asset); // must short-circuit at lockAndPrecheck

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_SUCCEEDED, $asset->status);

        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(1, $charges);

        $expectedCharge = CreditMath::chargeMicroUsd(0.40, 2.5);
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $context['account']->fresh()->balance_micro_usd);
    }

    public function test_insufficient_credits_cancels_before_reserving_or_calling(): void
    {
        // The CreditGate is the ONLY gate on the banner money path (no LeadGate). A merchant
        // out of credits must be denied BEFORE the reserve and BEFORE any provider call:
        // pending -> cancelled, nothing reserved, zero charge rows, no wasted render.
        $this->fakeOpenRouterSuccess(); // installed so we can prove it is NEVER called
        $context = $this->makeBannerContext();
        Tenant::run($context['account'], fn () => Account::query()
            ->whereKey($context['account']->id)->update(['balance_micro_usd' => 1_000]));
        $asset = $this->makePendingAsset($context);

        $this->runJob($context, $asset);

        // The gate ran before the provider call — no OpenRouter request was sent.
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_CANCELLED, $asset->status);
        $this->assertSame(GenerationFailureCode::INSUFFICIENT_CREDITS, $asset->failure_code);
        $this->assertNull($asset->charge_ledger_id);

        $account = $context['account']->fresh();
        $this->assertSame(1_000, $account->balance_micro_usd); // untouched
        $this->assertSame(0, $account->reserved_micro_usd);     // nothing reserved

        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);
    }

    public function test_failed_handler_recovers_an_asset_stranded_before_processing(): void
    {
        // The real "banner_generation op not seeded" strand: the resolver throws BEFORE the
        // PROCESSING transition, so process() cannot finalize and the asset would sit pending
        // forever (error only in failed_jobs). The failed() safety net must recover it.
        AiOperation::query()->where('operation_key', AiOperation::KEY_BANNER_GENERATION)->delete();
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);

        $job = new GenerateBannerJob((int) $context['account']->id, (int) $context['site']->id, (int) $asset->id);

        try {
            $job->handle();
            $this->fail('expected the missing operation to throw');
        } catch (\Throwable $e) {
            // The queue worker invokes failed() after the final attempt fails (tries=1).
            $job->failed($e);
        }

        $asset->refresh();
        // A pre-start failure ends 'cancelled' (like a gate/misconfig denial), carrying the cause.
        $this->assertSame(BannerAsset::STATUS_CANCELLED, $asset->status);
        $this->assertSame(GenerationFailureCode::INTERNAL_ERROR, $asset->failure_code);
        $this->assertNotEmpty($asset->meta[BannerAsset::META_FAILURE_MESSAGE] ?? null);

        // Money-safe: NO charge row, nothing left reserved.
        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);
    }

    public function test_failed_handler_releases_a_held_reservation_and_marks_in_flight_failed(): void
    {
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);

        // Simulate the state after reserve() ran but a later step threw uncaught (e.g. inside
        // finalizeSuccess): the asset is processing and a reservation is still held on its key.
        Tenant::run($context['account'], function () use ($context, $asset): void {
            app(ReservationManager::class)->reserve($context['account'], $asset->idempotency_key, 500_000);
            $asset->transitionTo(BannerAsset::STATUS_PROCESSING, ['model' => 'x']);
        });
        $this->assertSame(500_000, $context['account']->fresh()->reserved_micro_usd);

        $job = new GenerateBannerJob((int) $context['account']->id, (int) $context['site']->id, (int) $asset->id);
        $job->failed(new \RuntimeException('worker died mid-generation'));

        $asset->refresh();
        // An in-flight strand ends 'failed'; the reservation is released (never leaked); no charge.
        $this->assertSame(BannerAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::INTERNAL_ERROR, $asset->failure_code);
        $this->assertSame(0, $context['account']->fresh()->reserved_micro_usd);
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);
    }

    public function test_failed_handler_is_a_noop_on_an_already_terminal_asset(): void
    {
        // A caught failure (or committed success) already finalized the asset. A late failed()
        // call must not re-transition it or touch money.
        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $context = $this->makeBannerContext();
        $asset = $this->makePendingAsset($context);
        $this->runJob($context, $asset); // -> succeeded + charged once

        $balanceAfter = $context['account']->fresh()->balance_micro_usd;

        $job = new GenerateBannerJob((int) $context['account']->id, (int) $context['site']->id, (int) $asset->id);
        $job->failed(new \RuntimeException('a stray late failure'));

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_SUCCEEDED, $asset->status); // untouched
        $this->assertSame($balanceAfter, $context['account']->fresh()->balance_micro_usd);
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(1, $charges); // still exactly one
    }

    public function test_start_creates_a_pending_asset_and_dispatches_the_job(): void
    {
        Bus::fake([GenerateBannerJob::class]);
        $context = $this->makeBannerContext();

        $asset = Tenant::run($context['account'], fn () => app(StartBannerGeneration::class)->handle(
            new BannerGenerationRequest(
                banner: $context['banner'],
                brief: 'A vibrant end-of-season sale banner.',
                clientRequestId: 'crq-start-1',
            ),
        ));

        $this->assertSame(BannerAsset::STATUS_PENDING, $asset->status);
        $this->assertStringStartsWith('banner:', $asset->idempotency_key);
        $this->assertSame('A vibrant end-of-season sale banner.', $asset->brief);
        Bus::assertDispatchedTimes(GenerateBannerJob::class, 1);

        // A repeat of the SAME Generate click returns the existing asset, dispatches nothing new.
        $again = Tenant::run($context['account'], fn () => app(StartBannerGeneration::class)->handle(
            new BannerGenerationRequest(
                banner: $context['banner'],
                brief: 'A vibrant end-of-season sale banner.',
                clientRequestId: 'crq-start-1',
            ),
        ));
        $this->assertSame($asset->id, $again->id);
        Bus::assertDispatchedTimes(GenerateBannerJob::class, 1);
    }

    public function test_banner_generation_operation_resolves_from_the_seed(): void
    {
        $context = $this->makeBannerContext();

        $config = Tenant::run($context['account'], fn () => app(AiOperationResolver::class)
            ->for(AiOperation::KEY_BANNER_GENERATION, $context['site']));

        $this->assertSame(AiOperation::KEY_BANNER_GENERATION, $config->operationKey);
        $this->assertSame('16:9', $config->aspectRatio);
        $this->assertNotSame('', $config->userPrompt);
        // The brief placeholder is present so the merchant's brief is substituted (strtr).
        $this->assertStringContainsString('{{brief}}', $config->userPrompt);
    }

    public function test_banners_and_assets_are_account_isolated(): void
    {
        $a = $this->makeBannerContext();
        $b = $this->makeBannerContext();
        $assetA = $this->makePendingAsset($a);

        // Account B, under its own bound tenant, can never read A's banner or asset.
        $seenBanner = Tenant::run($b['account'], fn () => Banner::query()->find($a['banner']->id));
        $seenAsset = Tenant::run($b['account'], fn () => BannerAsset::query()->find($assetA->id));

        $this->assertNull($seenBanner);
        $this->assertNull($seenAsset);
    }
}
