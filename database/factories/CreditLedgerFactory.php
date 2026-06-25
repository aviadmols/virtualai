<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CreditLedger;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CreditLedger>
 *
 * For ISOLATION tests only — product code never inserts ledger rows directly (the
 * CreditLedgerService is the only writer). Sets account_id explicitly so the row
 * builds without a bound tenant; the idempotency_key is unique by default.
 */
class CreditLedgerFactory extends Factory
{
    protected $model = CreditLedger::class;

    public function definition(): array
    {
        $amount = 1_000_000;

        return [
            'account_id' => Account::factory(),
            'type' => CreditLedger::TYPE_GRANT,
            'amount_micro_usd' => $amount,
            'balance_after_micro_usd' => $amount,
            'idempotency_key' => 'test:'.Str::uuid()->toString(),
            'meta' => [],
            'created_at' => now(),
        ];
    }

    /** Build the row for an existing account. */
    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id]);
    }
}
