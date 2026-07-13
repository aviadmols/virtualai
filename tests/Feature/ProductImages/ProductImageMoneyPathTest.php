<?php

namespace Tests\Feature\ProductImages;

use App\Domain\Credits\CreditMath;
use App\Domain\Credits\ReservationManager;
use App\Domain\Generation\GenerationFailureCode;
use App\Domain\ProductImages\PollProductImageJob;
use App\Domain\ProductImages\StartProductImageBatch;
use App\Domain\ProductImages\SubmitProductImageJob;
use App\Models\Account;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The bulk product-image MONEY PATH — the async (submit -> poll -> finalize) lifecycle.
 *
 * Every law proved here, in the order the pipeline enforces them:
 *   reserve BEFORE the provider call · a submit stores the provider_request_id and NEVER runs
 *   twice · a poll RENEWS the hold so a long render can't strand it · charge ONLY on success,
 *   ONLY after the bytes are stored, and ONLY once · release on EVERY failure path (including
 *   an escaped exception via failed()) with ZERO charge rows · a bounded poll budget fails
 *   terminally without a charge · a transport blip retries the POLL, never the submit.
 *
 * Bus::fake() is mandatory here (TS-BUILD-007): the poller RE-DISPATCHES ITSELF, and the sync
 * queue driver would otherwise cascade the whole chain inside one handle() call — destroying
 * the very property each test exists to prove. Every job is driven by hand.
 */
class ProductImageMoneyPathTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    // The $5 opening grant, and what one fal nano-banana image costs/charges:
    //   flat rate $0.039 -> charge = round(0.039 × 2.5 × 1e6) = 97_500 micro-USD.
    private const FIVE_DOLLARS_MICRO = 5_000_000;

    private const FLAT_RATE_MICRO = 39_000;

    private const EXPECTED_CHARGE_MICRO = 97_500;

    // estimated_cost 40_000 × 2.5 = 100_000, floored by CreditEstimator to its 250_000 minimum.
    private const EXPECTED_RESERVE_MICRO = 250_000;

    // The seeded failures the tests below drive (real throws, not mocked domain classes).
    private const WRITE_EXPLODED = 'the product_assets write exploded';

    private const DISK_DOWN = 'the media disk is down';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class, PollProductImageJob::class]);
    }

    /**
     * Arm a ONE-SHOT failure on the next product_assets write. Inside SubmitProductImageJob that
     * lands on the row write at the very top of the reserve->hand-off window (the hold is already
     * taken), which is precisely the throw that failed() exists to clean up. It disarms itself so
     * the recovery path (which also writes the row) can run.
     */
    private function explodeOnNextProductAssetWrite(): void
    {
        $armed = true;

        ProductAsset::saving(function () use (&$armed): void {
            if (! $armed) {
                return;
            }

            $armed = false;

            throw new RuntimeException(self::WRITE_EXPLODED);
        });
    }

    /** The admin cleared the flat-rate price: the model, the operation estimate, and the fallback. */
    private function clearTheFlatRatePrice(): void
    {
        AiModel::query()
            ->where('operation_key', AiOperation::KEY_PACKSHOT_GENERATION)
            ->update(['cost_hint_micro_usd' => null, 'is_fallback' => false]);

        AiOperation::query()
            ->where('operation_key', AiOperation::KEY_PACKSHOT_GENERATION)
            ->update(['estimated_cost_micro_usd' => null, 'fallback_model' => null]);
    }

    /** A REAL storage failure: the media disk itself throws on write (an S3/R2 outage). */
    private function breakTheMediaDisk(): void
    {
        $broken = \Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put')->andThrow(new RuntimeException(self::DISK_DOWN));

        Storage::set('s3', $broken);
    }

    /**
     * The far more dangerous storage failure: the disk SILENTLY REFUSES the write. Every disk in
     * config/filesystems.php is `throw => false`, so a failed put() does not raise — it returns
     * FALSE. A write ATTEMPTED is not a write VERIFIED, and this is the shape that lied.
     */
    private function breakTheMediaDiskSilently(): void
    {
        $broken = \Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put')->andReturnFalse();
        $broken->shouldReceive('exists')->andReturnFalse();
        $broken->shouldReceive('size')->andReturnFalse();

        Storage::set('s3', $broken);
    }

    /** Start a one-product batch and return [account, site, asset]. */
    private function startBatch(array $shop = []): array
    {
        $shop = $shop !== [] ? $shop : $this->makeShop();

        $result = Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: ProductImageBatch::SOURCE_MAIN,
        ));

        $this->assertFalse($result->wasDenied());
        $this->assertSame(1, $result->queued);

        Bus::assertDispatched(SubmitProductImageJob::class);

        $asset = Tenant::run($shop['account'], fn () => ProductAsset::query()->latest('id')->firstOrFail());

        return [$shop['account'], $shop['site'], $asset];
    }

    private function runSubmit(Account $account, Site $site, ProductAsset $asset): void
    {
        (new SubmitProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    private function runPoll(Account $account, Site $site, ProductAsset $asset): void
    {
        (new PollProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    /** @return Collection<int,CreditLedger> */
    private function charges(Account $account)
    {
        return Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)
            ->get());
    }

    public function test_submit_reserves_before_the_call_and_stores_the_provider_request_id(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_PROCESSING, $asset->status);
        $this->assertSame('fal-req-123', $asset->provider_request_id);
        $this->assertSame('fal', $asset->provider);
        $this->assertSame(self::EXPECTED_RESERVE_MICRO, $asset->reserved_micro_usd);
        $this->assertNotEmpty($asset->meta[ProductAsset::META_PROMPT_SNAPSHOT] ?? '');

        // The hold is real: reserved on the account, nothing charged yet.
        $this->assertSame(self::EXPECTED_RESERVE_MICRO, $account->fresh()->reserved_micro_usd);
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertCount(0, $this->charges($account));

        // The render is queued upstream; the POLLER owns it from here.
        Bus::assertDispatched(PollProductImageJob::class);
        $this->assertSame(1, $this->falSubmitCount());
    }

    public function test_poll_success_stores_the_image_then_charges_exactly_once(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        $this->runPoll($account, $site, $asset);

        $this->assertSame(self::EXPECTED_CHARGE_MICRO, CreditMath::chargeMicroUsd(0.039, 2.5));

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_SUCCEEDED, $asset->status);
        $this->assertSame(ProductAsset::REVIEW_AWAITING, $asset->review_status);
        $this->assertNotNull($asset->image_path);
        $this->assertSame(self::EXPECTED_CHARGE_MICRO, $asset->charge_micro_usd);
        $this->assertSame(self::FLAT_RATE_MICRO, $asset->actual_cost_micro_usd);
        $this->assertNotNull($asset->charge_ledger_id);
        $this->assertSame(0, $asset->reserved_micro_usd);

        // The bytes are on the (private) media disk — stored BEFORE the charge.
        Storage::disk('s3')->assertExists($asset->image_path);
        $this->assertStringContainsString('/product-assets/', $asset->image_path);

        // ONE charge row, at the selling value, referencing the PRODUCT ASSET.
        $charges = $this->charges($account);
        $this->assertCount(1, $charges);
        $this->assertSame(-self::EXPECTED_CHARGE_MICRO, $charges->first()->amount_micro_usd);
        $this->assertSame(CreditLedger::REFERENCE_PRODUCT_ASSET, $charges->first()->reference_type);
        $this->assertSame((int) $asset->getKey(), $charges->first()->reference_id);

        // Balance debited once; the hold is fully released.
        $account->refresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO - self::EXPECTED_CHARGE_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        // The batch settled itself from its own row.
        $batch = Tenant::run($account, fn () => ProductImageBatch::query()->findOrFail($asset->batch_id));
        $this->assertSame(ProductImageBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->succeeded);
        $this->assertSame(self::EXPECTED_CHARGE_MICRO, $batch->charged_micro_usd);
    }

    /**
     * MUTATION GUARD — the ledger pre-check in PollProductImageJob::lockRenewAndClaim().
     * A stale/racing poll of an ALREADY-CHARGED asset must not re-poll the provider, must not
     * re-store the image, and must never write a second charge row. Delete that hasCharge()
     * check and the provider is polled again -> this test goes RED on the poll count.
     */
    public function test_a_racing_second_poll_never_charges_or_calls_the_provider_again(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        $this->runPoll($account, $site, $asset);

        $pollsAfterSuccess = $this->falPollCount();

        // Simulate a stale worker that still believes the render is in flight.
        Tenant::run($account, fn () => ProductAsset::query()
            ->whereKey($asset->getKey())
            ->update(['status' => ProductAsset::STATUS_PROCESSING]));

        $this->runPoll($account, $site, $asset);

        $this->assertSame($pollsAfterSuccess, $this->falPollCount(), 'A charged asset must never be polled again.');
        $this->assertCount(1, $this->charges($account));
        $this->assertSame(self::FIVE_DOLLARS_MICRO - self::EXPECTED_CHARGE_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertSame(1, $this->falSubmitCount(), 'The provider must never be re-submitted.');
    }

    /**
     * MUTATION GUARD — the submit-once wall in SubmitProductImageJob::lockAndPrecheck().
     * The crash-recovery shape: the provider ACCEPTED the render (a request id is on the row) but
     * the worker died before the status moved. A re-run must NOT submit a second render — we
     * would pay for it twice upstream. Delete the provider_request_id check and the submit count
     * becomes 2 -> RED.
     */
    public function test_a_resubmit_of_an_already_accepted_render_never_submits_again(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        $this->assertSame(1, $this->falSubmitCount());

        // The worker died mid-flight: the ticket is stored, but the row never left `pending`.
        Tenant::run($account, fn () => ProductAsset::query()
            ->whereKey($asset->getKey())
            ->update(['status' => ProductAsset::STATUS_PENDING]));

        $this->runSubmit($account, $site, $asset);

        $this->assertSame(1, $this->falSubmitCount(), 'An accepted render must never be submitted twice.');
        $this->assertCount(0, $this->charges($account));
    }

    /**
     * MUTATION GUARD — ReservationManager::renew() called from the poll tick.
     * A render longer than the reservation TTL (300s) must NOT let the hold lapse: if it did,
     * the terminal release would find no cache key, skip its decrement, and strand the hold on
     * accounts.reserved_micro_usd forever. Remove the renew() call and this test goes RED
     * (reserved stays at 250_000 after a successful, fully-charged generation).
     */
    public function test_a_long_render_renews_the_hold_so_it_never_lapses_mid_generation(): void
    {
        $this->falPendingThenComplete(1); // one IN_QUEUE tick, then COMPLETED

        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        $this->assertTrue(app(ReservationManager::class)->isHeld((string) $asset->idempotency_key));

        // 200s later: still rendering. The tick renews the hold for another full TTL.
        $this->travel(200)->seconds();
        $this->runPoll($account, $site, $asset);
        $this->assertSame(ProductAsset::STATUS_PROCESSING, $asset->fresh()->status);

        // 400s after the reserve — PAST the 300s TTL. Only the renewal keeps the hold alive.
        $this->travel(200)->seconds();
        $this->assertTrue(
            app(ReservationManager::class)->isHeld((string) $asset->idempotency_key),
            'The in-flight hold must survive a render longer than the reservation TTL.',
        );

        $this->runPoll($account, $site, $asset);

        $account->refresh();
        $this->assertSame(ProductAsset::STATUS_SUCCEEDED, $asset->fresh()->status);
        $this->assertSame(self::FIVE_DOLLARS_MICRO - self::EXPECTED_CHARGE_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd, 'The hold must be released exactly once, not stranded.');
        $this->assertFalse(app(ReservationManager::class)->isHeld((string) $asset->idempotency_key));
    }

    /**
     * MUTATION GUARD — the release in ProductImageFinalizer::fail().
     * A provider-side failure releases the hold and writes NO charge row. Remove the
     * `$this->ledger->release(...)` line and reserved_micro_usd stays at 250_000 -> RED.
     */
    public function test_a_failed_render_releases_the_hold_and_writes_no_charge(): void
    {
        $this->falFailsUpstream();

        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::AI_CALL_FAILED, $asset->failure_code);
        $this->assertNull($asset->image_path);
        $this->assertNull($asset->charge_ledger_id);

        $account->refresh();
        $this->assertCount(0, $this->charges($account), 'A failed render is NEVER charged.');
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd, 'The hold must be released on the failure path.');

        $batch = Tenant::run($account, fn () => ProductImageBatch::query()->findOrFail($asset->batch_id));
        $this->assertSame(ProductImageBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->failed);
        $this->assertSame(0, $batch->charged_micro_usd);
    }

    /** A transport blip retries the POLL — it can never re-submit (and re-pay for) the render. */
    public function test_a_network_blip_retries_the_poll_and_never_resubmits(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        Bus::assertDispatchedTimes(PollProductImageJob::class, 1);

        $this->falStatusBlip();
        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_PROCESSING, $asset->status, 'A blip is not a failure.');
        $this->assertSame(1, $asset->poll_attempts);
        $this->assertSame(1, $this->falSubmitCount(), 'A blip must NEVER re-submit the render.');
        Bus::assertDispatchedTimes(SubmitProductImageJob::class, 1);
        Bus::assertDispatchedTimes(PollProductImageJob::class, 2); // the retry is another POLL
        $this->assertCount(0, $this->charges($account));

        // Recovery: the very next tick completes the SAME upstream request and charges once.
        $this->falStatusRecovers();
        $this->runPoll($account, $site, $asset);

        $this->assertSame(ProductAsset::STATUS_SUCCEEDED, $asset->fresh()->status);
        $this->assertCount(1, $this->charges($account));
        $this->assertSame(1, $this->falSubmitCount());
    }

    /** The poll budget is bounded: exhausting it is a terminal failure with NO charge. */
    public function test_an_exhausted_poll_budget_fails_terminally_without_a_charge(): void
    {
        $this->falPendingThenComplete(99); // it never finishes

        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);

        // Jump to the last unit of the budget (MAX_ATTEMPTS = 60) so one tick exhausts it.
        Tenant::run($account, fn () => ProductAsset::query()
            ->whereKey($asset->getKey())
            ->update(['poll_attempts' => 59]));

        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::POLL_TIMEOUT, $asset->failure_code);
        $this->assertCount(0, $this->charges($account));
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertSame(0, $account->fresh()->reserved_micro_usd);
    }

    /**
     * MUTATION GUARD — the failed() handlers. An exception that escapes the worker entirely must
     * still release the hold (a leaked hold silently destroys spendable credit) and close the
     * asset. Gut PollProductImageJob::failed() and reserved stays at 250_000 -> RED.
     */
    public function test_an_escaped_exception_releases_the_hold_and_never_charges(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);
        $this->assertSame(self::EXPECTED_RESERVE_MICRO, $account->fresh()->reserved_micro_usd);

        (new PollProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))
            ->failed(new RuntimeException('worker exploded'));

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::INTERNAL_ERROR, $asset->failure_code);
        $this->assertCount(0, $this->charges($account));
        $this->assertSame(0, $account->fresh()->reserved_micro_usd, 'An escaped exception must never leak a hold.');
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
    }

    /**
     * MUTATION GUARD — SubmitProductImageJob::failed(), the ONLY release for a throw in the
     * window between the RESERVE and the provider hand-off (a failed row write, an illegal
     * transition, a Redis outage on the poll dispatch).
     *
     * A REAL throw is driven here: the row write that persists the hold blows up, right after the
     * reserve took it. The hold is live and the job is dead — exactly the shape that strands
     * accounts.reserved_micro_usd forever (spendable credit destroyed silently, with no ledger
     * row and no alarm). Gut failed() and reserved stays at 250_000 -> RED.
     */
    public function test_a_throw_between_the_reserve_and_the_handoff_releases_the_hold_and_never_charges(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->explodeOnNextProductAssetWrite();

        $job = new SubmitProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey());

        try {
            $job->handle();
            $this->fail('The seeded write failure must escape the job.');
        } catch (RuntimeException $e) {
            $this->assertSame(self::WRITE_EXPLODED, $e->getMessage());
        }

        // The reserve ran BEFORE the throw: the hold is live and the worker is gone.
        $this->assertSame(self::EXPECTED_RESERVE_MICRO, $account->fresh()->reserved_micro_usd);
        $this->assertSame(0, $this->falSubmitCount(), 'The provider was never reached.');

        // The queue now calls failed() — the only thing standing between the merchant and a
        // permanently stranded hold.
        $job->failed($e);

        $asset->refresh();
        $this->assertTrue($asset->isTerminal(), 'An escaped exception must close the asset.');
        $this->assertSame(GenerationFailureCode::INTERNAL_ERROR, $asset->failure_code);
        $this->assertCount(0, $this->charges($account), 'A dead job never charges.');
        $this->assertSame(
            0,
            $account->fresh()->reserved_micro_usd,
            'A throw between the reserve and the hand-off must never strand the hold.',
        );
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
    }

    /**
     * MUTATION GUARD — SubmitProductImageJob::flatRatePriceMissing() (the FIRST half of the
     * cost-honesty chain).
     *
     * A flat-rate model (fal returns no inline USD cost) whose per-image price the admin CLEARED
     * cannot produce an honest charge. It must fail CLOSED, BEFORE anything is spent: no reserve,
     * no provider call, no charge — never a silent charge at 0. Force the guard to false and the
     * asset renders and the provider is called -> RED.
     */
    public function test_a_flat_rate_model_with_no_configured_price_fails_closed_before_the_provider(): void
    {
        $this->clearTheFlatRatePrice();

        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_CANCELLED, $asset->status);
        $this->assertSame(GenerationFailureCode::AI_COST_NOT_CONFIGURED, $asset->failure_code);
        $this->assertSame(0, $this->falSubmitCount(), 'An unpriceable model must never render.');
        $this->assertCount(0, $this->charges($account), 'And must never charge — least of all at 0.');
        $this->assertSame(0, $account->fresh()->reserved_micro_usd, 'Nothing was reserved either.');
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertNull($asset->image_path);
    }

    /**
     * MUTATION GUARD — ProductImageFinalizer's cost-availability check (the SECOND half of the
     * cost-honesty chain).
     *
     * The render SUCCEEDED but its cost cannot be resolved (the flat-rate price the submit locked
     * in is gone). Cost is never guessed: the asset FAILS, the hold is released, no charge row is
     * written — and the bytes are never even stored, because the cost check runs BEFORE the store.
     * Force that check to false and the finalize charges from a null cost -> RED.
     */
    public function test_a_completed_render_with_an_unresolvable_cost_fails_and_never_charges(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);

        // The price behind this render is gone (cleared mid-flight): the finalize cannot be honest.
        Tenant::run($account, fn () => ProductAsset::query()
            ->whereKey($asset->getKey())
            ->update(['provider_meta' => json_encode([
                ProductAsset::PROVIDER_META_TICKET => $asset->fresh()->provider_meta[ProductAsset::PROVIDER_META_TICKET],
                ProductAsset::PROVIDER_META_FLAT_RATE_MICRO_USD => null,
            ])]));

        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::COST_UNAVAILABLE, $asset->failure_code);
        $this->assertNull($asset->image_path, 'The cost check runs BEFORE the store.');
        $this->assertNull($asset->charge_ledger_id);
        $this->assertCount(0, $this->charges($account), 'An unknown cost is never a charge.');
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertSame(0, $account->fresh()->reserved_micro_usd, 'The hold is released.');
    }

    /**
     * MUTATION GUARD — STORE BEFORE CHARGE (ProductImageFinalizer's storage-failure branch).
     *
     * The render succeeded and the cost is real — but the media disk is down. A credit is NEVER
     * debited for an image the merchant cannot see: the asset fails, the hold is released, and no
     * charge row exists. Neuter that branch and the merchant pays for a missing image -> RED.
     */
    public function test_a_storage_failure_after_a_successful_render_never_debits_the_merchant(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);

        $this->breakTheMediaDisk();

        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::STORAGE_FAILED, $asset->failure_code);
        $this->assertNull($asset->image_path);
        $this->assertNull($asset->charge_ledger_id);
        $this->assertCount(0, $this->charges($account), 'No charge for an image the merchant cannot see.');
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertSame(0, $account->fresh()->reserved_micro_usd);
    }

    /**
     * MUTATION GUARD — A WRITE ATTEMPTED IS NOT A WRITE VERIFIED (MediaStorage::write).
     *
     * The disk does not throw: it REFUSES the write and returns FALSE (every disk is
     * `throw => false`). Before MediaStorage checked that boolean, the finalizer got back a
     * StoredMedia whose path pointed at NOTHING, stamped the asset succeeded, and CHARGED the
     * merchant for an image that does not exist. Delete the boolean check (or the readback) in
     * MediaStorage::write() and this goes RED with a charge row for a phantom image.
     */
    public function test_a_silently_refused_disk_write_never_debits_the_merchant(): void
    {
        [$account, $site, $asset] = $this->startBatch();

        $this->runSubmit($account, $site, $asset);

        $this->breakTheMediaDiskSilently();

        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_FAILED, $asset->status);
        $this->assertSame(GenerationFailureCode::STORAGE_FAILED, $asset->failure_code);
        $this->assertNull($asset->image_path, 'A path that points at nothing must never be persisted.');
        $this->assertNull($asset->charge_ledger_id);
        $this->assertCount(0, $this->charges($account), 'No charge for bytes that never landed.');
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertSame(0, $account->fresh()->reserved_micro_usd, 'The hold must still be released.');
    }

    /** The merchant CreditGate: out of credits -> a typed cancel, no provider call, no charge. */
    public function test_an_out_of_credits_account_cancels_the_asset_without_calling_the_provider(): void
    {
        $shop = $this->makeShop();
        [$account, $site, $asset] = $this->startBatch($shop);

        // Drain the balance AFTER the batch was planned (the worker gate is the authoritative one).
        Account::query()->whereKey($account->getKey())->update(['balance_micro_usd' => 1_000]);

        $this->runSubmit($account, $site, $asset);

        $asset->refresh();
        $this->assertSame(ProductAsset::STATUS_CANCELLED, $asset->status);
        $this->assertSame(GenerationFailureCode::INSUFFICIENT_CREDITS, $asset->failure_code);
        $this->assertSame(0, $this->falSubmitCount(), 'A denied asset must never reach the provider.');
        $this->assertCount(0, $this->charges($account));
        $this->assertSame(0, $account->fresh()->reserved_micro_usd);
    }
}
