<?php

namespace Tests\Feature\Generation;

use App\Domain\Credits\CreditMath;
use App\Domain\Generation\GenerateTryOnJob;
use App\Domain\Generation\GenerationFailureCode;
use App\Domain\Media\MediaStorage;
use App\Models\ActivityEvent;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\EndUser;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The money path — the spine of Phase 6 and the ONLY place a credit is charged.
 *
 * Proves the laws end-to-end: reserve -> generate (mocked) -> store result -> charge
 * ONCE = round(cost × multiplier) -> release reservation; release on failure with
 * ZERO charge rows and the free-try NOT consumed; a double-dispatch collapses to one
 * charge. OpenRouter HTTP + S3 are faked — no real calls.
 */
class GenerateTryOnJobTest extends TestCase
{
    use GenerationTestSupport, RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGenerationEnv();
    }

    private function runJob(array $context, Generation $generation): void
    {
        (new GenerateTryOnJob(
            (int) $context['account']->id,
            (int) $context['site']->id,
            (int) $generation->id,
        ))->handle();
    }

    public function test_happy_path_reserves_generates_stores_and_charges_once(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40); // 0.40 × 2.5 = $1.00 = 1_000_000 micro
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $expectedCharge = CreditMath::chargeMicroUsd(0.40, 2.5);
        $this->assertSame(1_000_000, $expectedCharge);

        $account = $context['account']->fresh();
        // Charged exactly once; reservation released.
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertNotNull($generation->result_image_path);
        $this->assertNotNull($generation->charge_ledger_id);
        $this->assertSame(CreditMath::usdToMicro(0.40), $generation->actual_cost_micro_usd);

        // Exactly ONE charge row, at the selling value, linked to this generation.
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->get());
        $this->assertCount(1, $charges);
        $this->assertSame(-$expectedCharge, $charges->first()->amount_micro_usd);
        $this->assertSame($generation->charge_ledger_id, $charges->first()->id);

        // Result image stored on the faked media disk (not public).
        Storage::disk('s3')->assertExists($generation->result_image_path);

        // Lead funnel advanced + ONE free try consumed (only on a charged success).
        $endUser = $context['endUser']->fresh();
        $this->assertSame(EndUser::STATUS_GENERATED, $endUser->status);
        $this->assertSame(1, $endUser->generations_used);
    }

    public function test_signed_result_url_is_issued_and_not_a_public_path(): void
    {
        $this->fakeOpenRouterSuccess();
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);
        $generation->refresh();

        $media = app(MediaStorage::class);
        $signed = $media->signedUrl($generation->result_image_path);

        // The persisted ref is an opaque disk key, NOT a URL.
        $this->assertStringStartsWith('accounts/', $generation->result_image_path);
        $this->assertStringNotContainsString('http', $generation->result_image_path);
        // The browser-facing URL is signed (carries an expiration).
        $this->assertNotNull($signed);
        $this->assertStringContainsString('expiration=', $signed);
    }

    public function test_failure_path_releases_reservation_writes_no_charge_and_keeps_free_try(): void
    {
        $this->fakeOpenRouterOutage(); // every attempt + fallback 5xx
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $account = $context['account']->fresh();
        // Balance unchanged, reservation released — the merchant is NOT billed.
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(GenerationFailureCode::AI_CALL_FAILED, $generation->failure_code);
        $this->assertNull($generation->charge_ledger_id);

        // The hard assertion: ZERO charge rows.
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);

        // The free try is NOT consumed by a failed generation.
        $this->assertSame(0, $context['endUser']->fresh()->generations_used);
    }

    public function test_cost_unavailable_is_treated_as_a_failure_no_charge(): void
    {
        // A SUCCESS result whose cost is null (ParsedCost normalizes any null cost to
        // unavailable). The success-boundary guard routes it to finalizeFailure so
        // CreditMath::chargeMicroUsd(null) is never reached on any path.
        $this->fakeOpenRouterNoCost(); // image returned but no usable cost
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(GenerationFailureCode::COST_UNAVAILABLE, $generation->failure_code);
        $this->assertNull($generation->charge_ledger_id);

        // Cannot charge honestly with no real cost -> NO charge row, balance intact,
        // reservation RELEASED, and the free try is NOT consumed.
        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);
        $this->assertSame(0, $context['endUser']->fresh()->generations_used);
    }

    public function test_double_dispatch_same_generation_charges_once(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        // First run charges; the SECOND run for the SAME generation must DETERMINISTICALLY
        // short-circuit at lockAndPrecheck (isSucceeded || hasCharge -> return null), NEVER
        // reaching the transition backstop. We assert the clean short-circuit, not a thrown
        // illegal transition.
        $this->runJob($context, $generation);
        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);

        // Exactly ONE charge, balance debited once, one free try.
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(1, $charges);

        $expectedCharge = CreditMath::chargeMicroUsd(0.40, 2.5);
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $context['account']->fresh()->balance_micro_usd);
        $this->assertSame(1, $context['endUser']->fresh()->generations_used);

        // Proof the second run did NOT re-finalize: exactly ONE succeeded trace, and the
        // second run added NO second generation_requested (it returned null before that).
        $succeededTraces = Tenant::run($context['account'], fn () => ActivityEvent::query()
            ->where('subject_type', Generation::class)
            ->where('subject_id', $generation->id)
            ->where('kind', ActivityEvent::KIND_GENERATION_SUCCEEDED)
            ->count());
        $this->assertSame(1, $succeededTraces);
    }

    public function test_succeeded_generation_records_the_money_path_activity_trace(): void
    {
        $this->fakeOpenRouterSuccess();
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $kinds = Tenant::run($context['account'], fn () => ActivityEvent::query()
            ->where('subject_type', Generation::class)
            ->where('subject_id', $generation->id)
            ->pluck('kind')
            ->all());

        $this->assertContains(ActivityEvent::KIND_GENERATION_RESERVED, $kinds);
        $this->assertContains(ActivityEvent::KIND_GENERATION_PROCESSING, $kinds);
        $this->assertContains(ActivityEvent::KIND_GENERATION_SUCCEEDED, $kinds);
    }

    // === BytePlus (flat-rate provider) money-path — the cost_unavailable fix ===

    private const BYTEPLUS_BASE = 'https://ark.ap-southeast.bytepluses.com/api/v3';

    private const BYTEPLUS_GEN = self::BYTEPLUS_BASE.'/images/generations';

    /**
     * Make a BytePlus (Seedream) model the sole try-on model with the given per-image
     * price hint (micro-USD; null = no price). Removes the OpenRouter default/fallback so
     * EVERY usable attempt is flat-rate — the exact shape that hit cost_unavailable.
     */
    private function useBytePlusOnlyModel(?int $costHintMicroUsd): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BYTEPLUS_BASE);
        config()->set('services.byteplus.timeout', 30);
        config()->set('services.byteplus.probe_model', 'seedream-5-0-260128');
        Sleep::fake();

        // Drop the OpenRouter default + fallback so no attempt can return an inline cost.
        AiOperation::query()->where('operation_key', 'try_on_generation')
            ->update(['default_model' => 'seedream-5-0-260128', 'fallback_model' => null]);
        AiModel::query()->where('operation_key', 'try_on_generation')
            ->where('provider', AiModel::PROVIDER_OPENROUTER)
            ->update(['is_active' => false, 'is_default' => false, 'is_fallback' => false]);

        AiModel::query()->where('operation_key', 'try_on_generation')
            ->where('model_id', 'seedream-5-0-260128')
            ->update([
                'is_active' => true,
                'is_default' => true,
                'is_fallback' => false,
                'cost_hint_micro_usd' => $costHintMicroUsd,
            ]);
    }

    /** Fake a successful BytePlus images/generations response (b64 image, no cost field). */
    private function fakeBytePlusSuccess(): void
    {
        $png = "\x89PNG\r\n\x1a\nSEEDREAM-TRYON";

        Http::fake([
            self::BYTEPLUS_GEN => Http::response([
                'model' => 'seedream-5-0-260128',
                'data' => [['b64_json' => base64_encode($png)]],
            ], 200),
        ]);
    }

    public function test_byteplus_model_without_a_price_fails_before_any_provider_call(): void
    {
        // (a) A flat-rate model with NO configured price must be caught BEFORE the reserve
        // and BEFORE any BytePlus HTTP request — no render wasted, no charge, nothing held.
        $this->useBytePlusOnlyModel(costHintMicroUsd: null);
        // Also clear the operation estimate so the fallback price chain is truly empty.
        AiOperation::query()->where('operation_key', 'try_on_generation')
            ->update(['estimated_cost_micro_usd' => null]);
        Http::fake(); // record everything; assert nothing is sent

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        // ZERO provider calls — the failure happened before the BytePlus request.
        Http::assertNothingSent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::AI_COST_NOT_CONFIGURED, $generation->failure_code);
        $this->assertNull($generation->charge_ledger_id);
        // The stamped message is actionable: it names the model + points to the price.
        $this->assertStringContainsString('seedream-5-0-260128', (string) $generation->meta[Generation::META_FAILURE_MESSAGE]);

        // No charge, balance intact, nothing reserved, free try NOT consumed.
        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);
        $this->assertSame(0, $context['endUser']->fresh()->generations_used);
    }

    public function test_byteplus_model_with_a_price_generates_and_charges_price_times_markup(): void
    {
        // (b) A flat-rate model WITH a per-image price charges price × markup from the
        // per-model cost hint — proving AiModel.cost_hint_micro_usd now drives the charge.
        $priceMicro = 35_000; // $0.035 per image
        $this->useBytePlusOnlyModel(costHintMicroUsd: $priceMicro);
        $this->fakeBytePlusSuccess();

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertNotNull($generation->result_image_path);
        $this->assertNotNull($generation->charge_ledger_id);

        // The charge = per-image price × the default 2.5 markup, at the selling value.
        $priceUsd = CreditMath::microToUsd($priceMicro);
        $expectedCharge = CreditMath::chargeMicroUsd($priceUsd, 2.5);
        $this->assertSame(CreditMath::usdToMicro($priceUsd), $generation->actual_cost_micro_usd);

        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->get());
        $this->assertCount(1, $charges);
        $this->assertSame(-$expectedCharge, $charges->first()->amount_micro_usd);

        // The BytePlus endpoint WAS called (unlike the price-less case).
        Http::assertSent(fn ($req) => str_contains($req->url(), '/images/generations')
            && $req['model'] === 'seedream-5-0-260128');
        $this->assertSame(1, $context['endUser']->fresh()->generations_used);
    }
}
