<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The opening $5 grant. A new account gets EXACTLY ONE grant row of $5 micro-USD,
 * written through the ledger writer (never a bare column write), and the balance
 * reflects it. The grant is idempotent on the deterministic opening-grant key.
 */
class OpeningGrantTest extends TestCase
{
    use RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    public function test_new_account_has_exactly_one_grant_row_of_five_dollars(): void
    {
        $account = Account::factory()->create();

        $rows = Tenant::run($account, fn () => CreditLedger::query()->get());

        $this->assertCount(1, $rows);

        $grant = $rows->first();
        $this->assertSame(CreditLedger::TYPE_GRANT, $grant->type);
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $grant->amount_micro_usd);
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $grant->balance_after_micro_usd);
    }

    public function test_balance_reflects_the_opening_grant(): void
    {
        $account = Account::factory()->create();

        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->spendableMicroUsd());
    }

    public function test_opening_grant_uses_the_deterministic_key(): void
    {
        $account = Account::factory()->create();
        $key = IdempotencyKey::forGrant($account->id, IdempotencyKey::OPENING_GRANT_SLUG);

        $this->assertSame("grant:{$account->id}:opening", $key);
        $this->assertDatabaseHas('credit_ledger', [
            'account_id' => $account->id,
            'idempotency_key' => $key,
            'type' => CreditLedger::TYPE_GRANT,
        ]);
    }

    public function test_grant_is_idempotent_one_per_account(): void
    {
        $account = Account::factory()->create();

        // Re-run the observer's grant path: the same key must NOT create a 2nd row.
        $service = app(\App\Domain\Credits\CreditLedgerService::class);
        Tenant::run($account, fn () => $service->grant(
            account: $account,
            amountMicroUsd: self::FIVE_DOLLARS_MICRO,
            idempotencyKey: IdempotencyKey::forGrant($account->id, IdempotencyKey::OPENING_GRANT_SLUG),
            description: 'duplicate opening grant attempt',
        ));

        $count = Tenant::run($account, fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_GRANT)->count());
        $this->assertSame(1, $count);

        // The balance must NOT have doubled.
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
    }
}
