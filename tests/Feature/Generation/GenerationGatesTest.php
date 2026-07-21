<?php

namespace Tests\Feature\Generation;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Generation\GenerateTryOnJob;
use App\Domain\Generation\GenerationFailureCode;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The TWO independent gates (ARCHITECTURE.md): CreditGate (merchant has credits) AND
 * LeadGate (end user under the free limit / registered). Both must pass; a denial is
 * a TYPED outcome (cancelled + failure_code + activity trace), NEVER a 500 and NEVER
 * a charge, and crucially the OpenRouter call NEVER runs on a denial.
 */
class GenerationGatesTest extends TestCase
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

    public function test_lead_gate_blocks_unregistered_user_past_free_limit_without_calling_openrouter(): void
    {
        // A call would explode if it ran — the gate must short-circuit BEFORE any HTTP.
        Http::fake([self::OR_BASE.'/chat/completions' => Http::response([], 500)]);

        // Site allows 2 free tries; this anonymous lead already used 2.
        $context = $this->makeContext(['generations_used' => 2]);
        $context['site']->update(['free_generations_before_signup' => 2]);
        $generation = $this->makePendingGeneration($context);

        $this->runJob($context, $generation);

        $generation->refresh();
        // Typed signup-required outcome — cancelled (pre-processing refusal), not failed.
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::SIGNUP_REQUIRED, $generation->failure_code);

        // No OpenRouter call, no charge, free-tries count unchanged.
        Http::assertNothingSent();
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $context['account']->fresh()->balance_micro_usd);
        $this->assertSame(2, $context['endUser']->fresh()->generations_used);

        $kinds = $this->activityKinds($context['account'], $generation);
        $this->assertContains(ActivityEvent::KIND_LEAD_GATE_BLOCKED, $kinds);
    }

    public function test_credit_gate_blocks_when_merchant_is_out_of_credits_without_calling_openrouter(): void
    {
        Http::fake([self::OR_BASE.'/chat/completions' => Http::response([], 500)]);

        $context = $this->makeContext();
        // Drain the merchant's balance via an admin adjustment (through the ledger writer).
        Tenant::run($context['account'], function () use ($context) {
            app(CreditLedgerService::class)->adjustment(
                $context['account'],
                -self::FIVE_DOLLARS_MICRO,
                IdempotencyKey::forAdjustment($context['account']->id, 'drain'),
                'drain for test',
            );
        });

        $generation = $this->makePendingGeneration($context);
        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::INSUFFICIENT_CREDITS, $generation->failure_code);

        Http::assertNothingSent();
        // No charge row from this generation; reservation never taken.
        $this->assertSame(0, $context['account']->fresh()->reserved_micro_usd);
        $charges = Tenant::run($context['account'], fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);

        $kinds = $this->activityKinds($context['account'], $generation);
        $this->assertContains(ActivityEvent::KIND_CREDIT_GATE_BLOCKED, $kinds);
    }

    public function test_account_inactive_is_a_typed_credit_gate_denial(): void
    {
        Http::fake([self::OR_BASE.'/chat/completions' => Http::response([], 500)]);

        $context = $this->makeContext();
        $context['account']->update(['status' => Account::STATUS_SUSPENDED]);

        $generation = $this->makePendingGeneration($context);
        $this->runJob($context, $generation);

        $generation->refresh();
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::ACCOUNT_INACTIVE, $generation->failure_code);
        Http::assertNothingSent();
    }

    public function test_the_two_gates_are_independent_registered_user_still_blocked_by_credits(): void
    {
        // A registered end user (lead gate would pass) but the merchant is broke:
        // the CREDIT gate must still block. The gates never collapse into one.
        $this->fakeOpenRouterSuccess();

        $account = Account::factory()->create();
        // Registered users get UNLIMITED post-signup tries, so the LEAD gate PASSES and
        // only the CREDIT gate can block — isolating credit-gate independence.
        $site = Site::factory()->forAccount($account)->create([
            'free_generations_before_signup' => 2,
            'post_signup_grant' => ['type' => 'unlimited'],
        ]);

        $context = ['account' => $account, 'site' => $site] + Tenant::run($account, function () use ($account, $site) {
            $endUser = EndUser::factory()->forSite($site)->registered()->state(['generations_used' => 5])->create();
            $product = Product::factory()->forSite($site)->confirmed()->create(['product_type' => 'footwear']);
            $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['size' => 'M']]);

            // Drain the account so the credit gate fails.
            app(CreditLedgerService::class)->adjustment(
                $account,
                -self::FIVE_DOLLARS_MICRO,
                IdempotencyKey::forAdjustment($account->id, 'drain'),
                'drain',
            );

            return compact('endUser', 'product', 'variant');
        });

        $generation = $this->makePendingGeneration($context);
        $this->runJob($context, $generation);

        $generation->refresh();
        // Lead gate would allow (registered) but credit gate blocks — independence proven.
        $this->assertSame(Generation::STATUS_CANCELLED, $generation->status);
        $this->assertSame(GenerationFailureCode::INSUFFICIENT_CREDITS, $generation->failure_code);
    }

    private function activityKinds(Account $account, Generation $generation): array
    {
        return Tenant::run($account, fn () => ActivityEvent::query()
            ->where('subject_type', Generation::class)
            ->where('subject_id', $generation->id)
            ->pluck('kind')
            ->all());
    }
}
