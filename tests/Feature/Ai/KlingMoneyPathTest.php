<?php

namespace Tests\Feature\Ai;

use App\Domain\Credits\CreditMath;
use App\Domain\Generation\GenerateTryOnJob;
use App\Domain\Generation\GenerationFailureCode;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Generation\GenerationTestSupport;
use Tests\TestCase;

/**
 * Kling on the MONEY PATH, end to end.
 *
 * The law being proved: the price Kling BILLED (final_balance_deduction.list_price) is the charge —
 * the admin cost hint is only the reservation estimate and is used ONLY when the response carries
 * no cash price; with neither, the generation fails/cancels and NOT ONE credit is charged (never a
 * silent $0). Kling HTTP + the media disk are faked; no real call runs.
 */
class KlingMoneyPathTest extends TestCase
{
    use GenerationTestSupport, RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    private const MARKUP = 2.5;

    private const KLING_BASE = 'https://api-singapore.klingai.com';

    private const KLING_MODEL = 'kling-v2-1';

    private const SUBMIT = self::KLING_BASE.'/v1/images/generations';

    private const TASK = 'kling-task-1';

    private const RESULT_URL = 'https://cdn.klingai.test/tryon.png';

    // The admin hint (the estimate) and the REAL price Kling bills — deliberately different, so a
    // charge equal to the real price could not have come from the hint.
    private const HINT_MICRO_USD = 28_000;   // $0.028

    private const LIST_PRICE_USD = 0.056;    // what Kling actually deducted

    private const OPENROUTER_FALLBACK = 'google/gemini-2.5-flash-image';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGenerationEnv();

        config()->set('services.kling.api_key', 'api-key-kling-test');
        config()->set('services.kling.base_url', self::KLING_BASE);
        config()->set('services.kling.timeout', 30);
        Sleep::fake();
    }

    /**
     * Point try_on_generation at the seeded Kling model with the given per-image hint.
     * $fallbackModel = null makes Kling the ONLY attempt.
     */
    private function useKlingModel(?int $costHintMicroUsd, ?string $fallbackModel = null, ?int $operationEstimate = 40_000): void
    {
        AiOperation::query()->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)->update([
            'default_model' => self::KLING_MODEL,
            'fallback_model' => $fallbackModel,
            'estimated_cost_micro_usd' => $operationEstimate,
        ]);

        // The Kling row the seed migration already catalogued (never invented here).
        $updated = AiModel::query()
            ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->where('model_id', self::KLING_MODEL)
            ->where('provider', AiModel::PROVIDER_KLING)
            ->update([
                'is_active' => true,
                'is_default' => true,
                'is_fallback' => false,
                'cost_hint_micro_usd' => $costHintMicroUsd,
            ]);

        // The catalog row MUST come from the seeder — if it is missing, the seed is broken.
        $this->assertSame(1, $updated, 'The Kling try-on model is not catalogued (seed migration).');

        if ($fallbackModel === null) {
            AiModel::query()->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
                ->where('provider', AiModel::PROVIDER_OPENROUTER)
                ->update(['is_active' => false, 'is_default' => false, 'is_fallback' => false]);
        }
    }

    /**
     * Fake the whole Kling dance: submit -> poll (succeeded, carrying $deduction) -> result image.
     * The catch-all serves the INPUT image downloads (the signed source url + the variant url).
     *
     * @param  array<string,mixed>  $deduction
     */
    private function fakeKling(array $deduction): void
    {
        $png = "\x89PNG\r\n\x1a\nKLING-TRYON";

        Http::fake([
            self::SUBMIT.'/*' => Http::response([
                'code' => 0,
                'data' => [
                    'task_id' => self::TASK,
                    'task_status' => 'succeed',
                    'task_result' => ['images' => [['index' => 0, 'url' => self::RESULT_URL]]],
                ] + $deduction,
            ], 200),
            self::SUBMIT => Http::response([
                'code' => 0,
                'data' => ['task_id' => self::TASK, 'task_status' => 'submitted'],
            ], 200),
            self::RESULT_URL => Http::response($png, 200, ['Content-Type' => 'image/png']),
            // Input images (the signed shopper photo + the variant photo) are downloaded + inlined.
            '*' => Http::response('INPUTBYTES', 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function runJob(array $context, Generation $generation): void
    {
        (new GenerateTryOnJob(
            (int) $context['account']->id,
            (int) $context['site']->id,
            (int) $generation->id,
        ))->handle();
    }

    private function chargeRows(array $context): Collection
    {
        return Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->get());
    }

    public function test_the_real_list_price_is_charged_not_the_admin_hint(): void
    {
        $this->useKlingModel(costHintMicroUsd: self::HINT_MICRO_USD);
        $this->fakeKling(['final_balance_deduction' => ['quota' => 56, 'list_price' => '0.056']]);

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertNotNull($generation->result_image_path);

        // The recorded cost is Kling's OWN price ($0.056), NOT the $0.028 hint.
        $this->assertSame(56_000, $generation->actual_cost_micro_usd);

        $expectedCharge = CreditMath::chargeMicroUsd(self::LIST_PRICE_USD, self::MARKUP); // 140_000
        $this->assertSame(140_000, $expectedCharge);

        $charges = $this->chargeRows($context);
        $this->assertCount(1, $charges);
        $this->assertSame(-$expectedCharge, $charges->first()->amount_micro_usd);

        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);
    }

    public function test_a_unit_billed_response_falls_back_to_the_admin_hint(): void
    {
        // A resource-package account: Kling deducts UNITS and returns no cash price at all.
        $this->useKlingModel(costHintMicroUsd: self::HINT_MICRO_USD);
        $this->fakeKling(['final_unit_deduction' => ['quota' => 1, 'package_type' => 'image_pack']]);

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame(self::HINT_MICRO_USD, $generation->actual_cost_micro_usd);

        $expectedCharge = CreditMath::chargeMicroUsd(CreditMath::microToUsd(self::HINT_MICRO_USD), self::MARKUP);
        $charges = $this->chargeRows($context);
        $this->assertCount(1, $charges);
        $this->assertSame(-$expectedCharge, $charges->first()->amount_micro_usd);
    }

    public function test_no_cash_price_and_no_hint_charges_nothing_at_all(): void
    {
        // THE FAIL-CLOSED GUARD (KlingImageClient::parseCost step 3). The Kling attempt SUCCEEDS
        // but carries no cash price and has no configured price; an OpenRouter fallback exists, so
        // the pre-flight "no flat rate configured" gate does NOT fire and the response really does
        // reach parseCost. An honest "unavailable" must fail the generation with ZERO charge —
        // never a silent $0 charge, never a guessed number.
        $this->useKlingModel(
            costHintMicroUsd: null,
            fallbackModel: self::OPENROUTER_FALLBACK,
            operationEstimate: null,
        );
        $this->fakeKling([]); // succeeded task, no deduction block at all

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(GenerationFailureCode::COST_UNAVAILABLE, $generation->failure_code);
        $this->assertNull($generation->charge_ledger_id);

        // Not one credit moved: no charge row, balance intact, reservation released, free try kept.
        $this->assertCount(0, $this->chargeRows($context));

        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);
        $this->assertSame(0, $context['endUser']->fresh()->generations_used);
    }

    public function test_a_priceless_kling_only_model_never_even_calls_the_provider(): void
    {
        // The pre-flight gate: every usable attempt is flat-rate with no price -> cancelled BEFORE
        // any spend (no render wasted, nothing reserved, nothing charged).
        $this->useKlingModel(costHintMicroUsd: null, fallbackModel: null, operationEstimate: null);
        Http::fake();

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        Http::assertNothingSent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::AI_COST_NOT_CONFIGURED, $generation->failure_code);
        $this->assertCount(0, $this->chargeRows($context));
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $context['account']->fresh()->balance_micro_usd);
    }
}
