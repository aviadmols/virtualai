<?php

namespace Tests\Feature\Credits;

use App\Models\Account;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money-safety guard (Phase-6 pre-work S4): the account's money columns
 * (balance_micro_usd, reserved_micro_usd) must NEVER be mass-assignable. Only
 * CreditLedgerService / ReservationManager move them, and only via forceFill()
 * under a row lock. A mass-assigned balance is a money hole — this proves it can't
 * happen, even with a hostile create()/update() payload.
 */
class AccountMoneyColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_columns_are_not_in_fillable(): void
    {
        $account = new Account;

        $this->assertNotContains('balance_micro_usd', $account->getFillable());
        $this->assertNotContains('reserved_micro_usd', $account->getFillable());
    }

    public function test_create_ignores_a_mass_assigned_balance(): void
    {
        // A hostile mass-assign through the MODEL (the path product/admin code uses)
        // tries to mint $1,000,000 of credit + a fake reservation. Both are dropped
        // by the fillable guard; the only balance set is the genuine $5 opening grant
        // written by the observer through the ledger writer.
        $account = Account::create([
            'name' => 'Hostile Co',
            'billing_email' => 'hostile@example.test',
            'balance_micro_usd' => 1_000_000_000_000,
            'reserved_micro_usd' => 9_999_999,
        ]);

        $account->refresh();

        $this->assertSame(5_000_000, $account->balance_micro_usd); // opening grant only
        $this->assertSame(0, $account->reserved_micro_usd);
    }

    public function test_update_ignores_a_mass_assigned_balance(): void
    {
        $account = Account::factory()->create();
        $opening = $account->fresh()->balance_micro_usd;

        Tenant::run($account, function () use ($account) {
            // A mass-update with a money column is a no-op for that column.
            $account->update([
                'name' => 'Renamed',
                'balance_micro_usd' => 0,
                'reserved_micro_usd' => 42,
            ]);
        });

        $account->refresh();
        $this->assertSame('Renamed', $account->name);
        $this->assertSame($opening, $account->balance_micro_usd); // unchanged
        $this->assertSame(0, $account->reserved_micro_usd);       // unchanged
    }
}
