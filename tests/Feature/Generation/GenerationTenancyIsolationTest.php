<?php

namespace Tests\Feature\Generation;

use App\Domain\Generation\GenerateTryOnJob;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant-isolation is a RELEASE BLOCKER (the TS-TENANCY-001 shape applied to the
 * generation pipeline). Two accounts' generation jobs run BACK-TO-BACK on one worker
 * process; each charge must land on the right account, the worker must never leak a
 * bound Tenant between jobs, and account A must never read account B's generations or
 * ledger through the fail-closed global scope.
 */
class GenerationTenancyIsolationTest extends TestCase
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

    public function test_back_to_back_two_account_generations_charge_the_right_account_and_do_not_leak_tenant(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40);

        $contextA = $this->makeContext();
        $contextB = $this->makeContext();
        $genA = $this->makePendingGeneration($contextA, 'crq-A');
        $genB = $this->makePendingGeneration($contextB, 'crq-B');

        // A fresh worker: no tenant bound.
        $this->assertFalse(Tenant::check());

        // A's job, then B's job, consecutively on the same process.
        $this->runJob($contextA, $genA);
        $this->assertFalse(Tenant::check(), 'Tenant leaked after Account A generation');

        $this->runJob($contextB, $genB);
        $this->assertFalse(Tenant::check(), 'Tenant leaked after Account B generation');

        $expectedCharge = \App\Domain\Credits\CreditMath::chargeMicroUsd(0.40, 2.5);

        // Each account charged exactly once, on its OWN ledger.
        $aCharges = Tenant::run($contextA['account'], fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->get());
        $bCharges = Tenant::run($contextB['account'], fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->get());
        $this->assertCount(1, $aCharges);
        $this->assertCount(1, $bCharges);
        $this->assertSame((int) $contextA['account']->id, (int) $aCharges->first()->account_id);
        $this->assertSame((int) $contextB['account']->id, (int) $bCharges->first()->account_id);

        // Balances debited on the correct account.
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $contextA['account']->fresh()->balance_micro_usd);
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $expectedCharge, $contextB['account']->fresh()->balance_micro_usd);
    }

    public function test_account_a_cannot_read_account_b_generations_through_the_global_scope(): void
    {
        $this->fakeOpenRouterSuccess();

        $contextA = $this->makeContext();
        $contextB = $this->makeContext();
        $genA = $this->makePendingGeneration($contextA, 'crq-A');
        $genB = $this->makePendingGeneration($contextB, 'crq-B');

        // Bound as A: only A's generation is visible; B's is invisible (fail-closed).
        Tenant::run($contextA['account'], function () use ($genA, $genB) {
            $this->assertSame(1, Generation::query()->count());
            $this->assertNotNull(Generation::query()->find($genA->id));
            $this->assertNull(Generation::query()->find($genB->id)); // cross-account read fails closed
        });

        // Bound as B: the mirror image.
        Tenant::run($contextB['account'], function () use ($genA, $genB) {
            $this->assertSame(1, Generation::query()->count());
            $this->assertNotNull(Generation::query()->find($genB->id));
            $this->assertNull(Generation::query()->find($genA->id));
        });
    }

    public function test_account_a_cannot_read_account_b_charge_ledger(): void
    {
        $this->fakeOpenRouterSuccess();

        $contextA = $this->makeContext();
        $contextB = $this->makeContext();
        $this->runJob($contextA, $this->makePendingGeneration($contextA, 'crq-A'));
        $this->runJob($contextB, $this->makePendingGeneration($contextB, 'crq-B'));

        // Bound as A, the charge ledger only ever shows A's rows.
        $accountIds = Tenant::run($contextA['account'], fn () => CreditLedger::query()->pluck('account_id')->unique()->all());
        $this->assertSame([(int) $contextA['account']->id], array_map('intval', $accountIds));
    }
}
