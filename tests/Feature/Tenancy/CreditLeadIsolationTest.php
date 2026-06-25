<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\EndUser;
use App\Models\Site;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tenant-isolation gate for the Phase-5a tables (the release blocker; kept
 * obvious for the saas-credits-billing audit). credit_ledger and end_users are
 * account-scoped (BelongsToAccount, NOT on the global allow-list): account B can
 * never read account A's ledger / leads, and every idempotency key carries
 * account_id so a key cannot be mistaken across accounts.
 */
class CreditLeadIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_ledger_and_end_user_are_not_on_the_global_allow_list(): void
    {
        // They are tenant-owned; the audit asserts they are NOT exempt.
        $this->assertFalse(GlobalModels::isGlobal(CreditLedger::class));
        $this->assertFalse(GlobalModels::isGlobal(EndUser::class));
    }

    public function test_account_b_cannot_read_account_a_ledger(): void
    {
        $accountA = Account::factory()->create(); // each has an opening grant row
        $accountB = Account::factory()->create();

        // A extra adjustment so A has 2 rows, B has 1.
        $ledger = app(CreditLedgerService::class);
        Tenant::run($accountA, fn () => $ledger->adjustment(
            $accountA, 1_000_000, IdempotencyKey::forAdjustment($accountA->id, 'topup'), 'topup'
        ));

        // Bound as B, the global scope returns only B's ledger (its 1 grant).
        $seenAsB = Tenant::run($accountB, fn () => CreditLedger::query()->get());
        $this->assertCount(1, $seenAsB);
        $this->assertTrue($seenAsB->every(fn (CreditLedger $r) => $r->account_id === $accountB->id));

        // Bound as A, A sees its 2 rows.
        $seenAsA = Tenant::run($accountA, fn () => CreditLedger::query()->get());
        $this->assertCount(2, $seenAsA);

        // B cannot fetch A's grant row by id even with an explicit query.
        $aRowId = $seenAsA->first()->id;
        $crossRead = Tenant::run($accountB, fn () => CreditLedger::query()->where('id', $aRowId)->first());
        $this->assertNull($crossRead);
    }

    public function test_account_b_cannot_read_account_a_leads(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();
        $siteB = Site::factory()->forAccount($accountB)->create();

        EndUser::factory()->forSite($siteA)->count(3)->create();
        EndUser::factory()->forSite($siteB)->count(2)->create();

        $seenAsA = Tenant::run($accountA, fn () => EndUser::query()->get());
        $this->assertCount(3, $seenAsA);
        $this->assertTrue($seenAsA->every(fn (EndUser $u) => $u->account_id === $accountA->id));

        $seenAsB = Tenant::run($accountB, fn () => EndUser::query()->get());
        $this->assertCount(2, $seenAsB);

        // A cannot read a specific B lead by id.
        $bLeadId = $seenAsB->first()->id;
        $crossRead = Tenant::run($accountA, fn () => EndUser::query()->where('id', $bLeadId)->first());
        $this->assertNull($crossRead);
    }

    public function test_unbound_ledger_query_fails_closed(): void
    {
        $account = Account::factory()->create(); // has a grant row

        Tenant::clear();
        // No tenant bound -> the scope fails closed (sentinel) -> empty set.
        $this->assertCount(0, CreditLedger::query()->get());
        $this->assertNull(CreditLedger::query()->first());
    }

    public function test_idempotency_keys_carry_account_id(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        $genA = IdempotencyKey::forGeneration($accountA->id, 1, 1, 1, ['c' => 'Red'], 'req-1');
        $genB = IdempotencyKey::forGeneration($accountB->id, 1, 1, 1, ['c' => 'Red'], 'req-1');

        // Same logical generation, DIFFERENT accounts -> different keys.
        $this->assertNotSame($genA, $genB);
        $this->assertStringContainsString(":{$accountA->id}:", $genA);
        $this->assertStringContainsString(":{$accountB->id}:", $genB);

        $refundA = IdempotencyKey::forRefund($accountA->id, 99);
        $this->assertSame("refund:{$accountA->id}:99", $refundA);
    }

    public function test_a_charge_for_account_a_lands_on_account_a_back_to_back(): void
    {
        // Back-to-back two-account writes on one process: each charge lands on its
        // own account, no stale-tenant leak (the TS-TENANCY-001 class of bug).
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $ledger = app(CreditLedgerService::class);

        Tenant::run($accountA, fn () => $ledger->charge(
            $accountA, 1_000_000, 400_000,
            IdempotencyKey::forGeneration($accountA->id, 1, 1, 1, ['x' => 1], 'a'),
            generationId: 1,
        ));
        $this->assertFalse(Tenant::check(), 'Tenant leaked after Account A charge');

        Tenant::run($accountB, fn () => $ledger->charge(
            $accountB, 2_000_000, 800_000,
            IdempotencyKey::forGeneration($accountB->id, 1, 1, 1, ['x' => 1], 'b'),
            generationId: 1,
        ));
        $this->assertFalse(Tenant::check(), 'Tenant leaked after Account B charge');

        // Each account's charge debited only its own balance.
        $this->assertSame(5_000_000 - 1_000_000, $accountA->fresh()->balance_micro_usd);
        $this->assertSame(5_000_000 - 2_000_000, $accountB->fresh()->balance_micro_usd);

        // Raw DB: exactly one charge per account, no cross-stamping.
        $this->assertSame(1, \DB::table('credit_ledger')->where('account_id', $accountA->id)->where('type', 'charge')->count());
        $this->assertSame(1, \DB::table('credit_ledger')->where('account_id', $accountB->id)->where('type', 'charge')->count());
    }
}
