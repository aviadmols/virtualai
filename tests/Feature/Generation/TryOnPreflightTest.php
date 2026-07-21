<?php

namespace Tests\Feature\Generation;

use App\Domain\Generation\GenerateTryOnJob;
use App\Domain\Generation\GenerationFailureCode;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\EndUser;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Slice E — the try-on PREFLIGHT. It runs after both gates and before reserve, so:
 *  - a REJECTED photo cancels the try-on with ZERO spend and ZERO free tries burned, and never
 *    calls the image model;
 *  - a PASS with refinement appends that guidance to the prompt that is actually sent;
 *  - it is FAIL-OPEN: a missing/edited preflight operation leaves the try-on exactly as before.
 */
class TryOnPreflightTest extends TestCase
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

    public function test_a_rejected_photo_cancels_before_reserve_with_no_charge_and_no_free_try(): void
    {
        // The preflight returns usable=false; the image generation must NEVER be reached.
        Http::fake([
            self::OR_BASE.'/chat/completions' => fn ($request) => $this->isPreflightRequest($request)
                ? $this->preflightResponse(false, 'No person is visible in the photo.')
                : Http::response(['error' => 'the image model must not be called'], 500),
        ]);

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::PHOTO_REJECTED, $generation->failure_code);
        $this->assertSame('No person is visible in the photo.', $generation->meta[Generation::META_FAILURE_MESSAGE]);

        // No money moved and no free try consumed.
        $account = $context['account']->fresh();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        $this->assertSame(0, Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count()));
        $this->assertSame(0, (int) EndUser::query()->whereKey($context['endUser']->id)->value('generations_used'));

        // The image model was never called (only the preflight went out).
        Http::assertSent(fn ($req) => $this->isPreflightRequest($req));
    }

    public function test_a_pass_with_refinement_appends_it_to_the_generated_prompt(): void
    {
        $dataUrl = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\nX");

        Http::fake([
            self::OR_BASE.'/chat/completions' => function ($request) use ($dataUrl) {
                if ($this->isPreflightRequest($request)) {
                    return $this->preflightResponse(true, '', 'Preserve the three-quarter pose and soft window light.');
                }

                return Http::response([
                    'id' => 'or-gen',
                    'model' => 'google/gemini-2.5-flash-image',
                    'usage' => ['cost' => 0.40],
                    'choices' => [['message' => ['images' => [['image_url' => ['url' => $dataUrl]]]]]],
                ], 200);
            },
        ]);

        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        // The snapshotted prompt (what we actually asked the model) carries the refinement.
        $this->assertStringContainsString(
            'Preserve the three-quarter pose and soft window light.',
            (string) $generation->meta[Generation::META_PROMPT_SNAPSHOT],
        );
    }

    public function test_the_try_on_runs_normally_when_the_preflight_operation_is_absent(): void
    {
        // Remove the preflight operation entirely -> the resolver throws -> fail-open -> the
        // try-on proceeds and succeeds exactly as it did before Slice E.
        AiOperation::query()->where('operation_key', AiOperation::KEY_TRY_ON_PREFLIGHT)->delete();

        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $context = $this->makeContext();
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->refresh()->status);
    }
}
