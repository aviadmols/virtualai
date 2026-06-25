<?php

namespace Tests\Feature\Credits;

use App\Models\Account;
use App\Models\CreditLedger;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger is APPEND-ONLY. A loaded row can never be updated or deleted — a
 * correction is a NEW row (a refund reverses; an adjustment is admin ±). The model
 * boot guards both vectors so a financial row can never be silently mutated.
 */
class AppendOnlyLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_ledger_row_throws(): void
    {
        $account = Account::factory()->create(); // opening grant row exists

        $row = Tenant::run($account, fn () => CreditLedger::query()->first());
        $this->assertNotNull($row);

        $this->expectException(RuntimeException::class);

        Tenant::run($account, function () use ($row) {
            $row->amount_micro_usd = 999_999_999;
            $row->save(); // must throw — the ledger is append-only
        });
    }

    public function test_deleting_a_ledger_row_throws(): void
    {
        $account = Account::factory()->create();

        $row = Tenant::run($account, fn () => CreditLedger::query()->first());

        $this->expectException(RuntimeException::class);

        Tenant::run($account, fn () => $row->delete()); // must throw
    }

    public function test_the_row_is_unchanged_after_a_blocked_update(): void
    {
        $account = Account::factory()->create();
        $row = Tenant::run($account, fn () => CreditLedger::query()->first());
        $original = $row->amount_micro_usd;

        try {
            Tenant::run($account, function () use ($row) {
                $row->amount_micro_usd = 1;
                $row->save();
            });
        } catch (RuntimeException) {
            // expected
        }

        $reloaded = Tenant::run($account, fn () => CreditLedger::query()->first());
        $this->assertSame($original, $reloaded->amount_micro_usd);
    }

    public function test_the_table_has_no_updated_at_column(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('credit_ledger', 'updated_at'),
            'credit_ledger must not carry updated_at — it is append-only.'
        );
    }
}
