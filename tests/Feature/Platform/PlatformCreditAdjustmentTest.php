<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\PlatformCreditAdjustment;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP-P3 — the super-admin manual credit-adjust action. Money-touching: every change is
 * one append-only adjustment ledger row through the existing CreditLedgerService (never a
 * bare balance). Upward adjusts add; a downward adjust floors the balance at 0; the same
 * deterministic ref is idempotent (one row); a non-super-admin is denied.
 */
class PlatformCreditAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    private function action(): PlatformCreditAdjustment
    {
        return app(PlatformCreditAdjustment::class);
    }

    private function asSuperAdmin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_upward_adjustment_writes_a_ledger_row_and_moves_the_balance(): void
    {
        $this->asSuperAdmin();
        $account = Account::factory()->create(); // $5 opening grant

        $row = $this->action()->apply($account, 2_000_000, 'topup-1', 'goodwill credit');

        $this->assertSame(CreditLedger::TYPE_ADJUSTMENT, $row->type);
        $this->assertSame(2_000_000, $row->amount_micro_usd);
        $this->assertSame(self::FIVE_DOLLARS_MICRO + 2_000_000, $account->fresh()->balance_micro_usd);

        // It went through the ledger (one adjustment row + the credit_adjusted trace).
        $event = Tenant::run($account, fn () => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_CREDIT_ADJUSTED)->first());
        $this->assertNotNull($event);
    }

    public function test_downward_adjustment_past_zero_is_floored_at_zero(): void
    {
        $this->asSuperAdmin();
        $account = Account::factory()->create(); // balance 5_000_000

        // Claw back $9 from a $5 balance -> floored to -$5, balance lands at exactly 0.
        $row = $this->action()->apply($account, -9_000_000, 'clawback-1', 'overdraw clawback');

        $this->assertSame(-self::FIVE_DOLLARS_MICRO, $row->amount_micro_usd);
        $this->assertSame(0, $account->fresh()->balance_micro_usd);
        // Balance never goes negative.
        $this->assertGreaterThanOrEqual(0, $account->fresh()->balance_micro_usd);
    }

    public function test_downward_adjustment_within_balance_is_not_clamped(): void
    {
        $this->asSuperAdmin();
        $account = Account::factory()->create();

        $row = $this->action()->apply($account, -1_000_000, 'clawback-partial', 'partial clawback');

        $this->assertSame(-1_000_000, $row->amount_micro_usd);
        $this->assertSame(self::FIVE_DOLLARS_MICRO - 1_000_000, $account->fresh()->balance_micro_usd);
    }

    public function test_same_reference_twice_yields_a_single_row(): void
    {
        $this->asSuperAdmin();
        $account = Account::factory()->create();

        $first = $this->action()->apply($account, 2_000_000, 'same-ref', 'first');
        $second = $this->action()->apply($account, 2_000_000, 'same-ref', 'second (dup)');

        // Idempotent: the same ref returns the SAME row, no second debit/credit.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(self::FIVE_DOLLARS_MICRO + 2_000_000, $account->fresh()->balance_micro_usd);

        $rows = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_ADJUSTMENT)->count());
        $this->assertSame(1, $rows);
    }

    public function test_a_non_super_admin_is_denied_and_writes_nothing(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        try {
            $this->action()->apply($account, 1_000_000, 'blocked', 'should not apply');
            $this->fail('Expected PlatformAccessRequiredException for a non-super-admin.');
        } catch (PlatformAccessRequiredException) {
            // expected
        }

        // No adjustment row was written; balance is the untouched opening grant.
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $rows = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_ADJUSTMENT)->count());
        $this->assertSame(0, $rows);
    }

    public function test_a_missing_reference_falls_back_to_a_unique_key_each_call(): void
    {
        $this->asSuperAdmin();
        $account = Account::factory()->create();

        // No ref -> a UUID per call -> two distinct rows (not idempotent without a ref).
        $this->action()->apply($account, 1_000_000, null, 'a');
        $this->action()->apply($account, 1_000_000, null, 'b');

        $rows = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_ADJUSTMENT)->count());
        $this->assertSame(2, $rows);
    }
}
