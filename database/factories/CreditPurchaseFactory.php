<?php

namespace Database\Factories;

use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\CreditPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CreditPurchase>
 *
 * Sets account_id explicitly so a row builds without a bound tenant (isolation tests).
 * The idempotency_key is derived from the same account + provider + ref the purchase
 * flow uses, so it matches the production shape.
 */
class CreditPurchaseFactory extends Factory
{
    protected $model = CreditPurchase::class;

    public function definition(): array
    {
        $account = Account::factory();
        $provider = CreditPurchase::PROVIDER_PAYPLUS;
        $ref = 'pp_'.Str::random(20);
        $amountUsd = 10.00;

        return [
            'account_id' => $account,
            'provider' => $provider,
            'provider_ref' => $ref,
            'amount_usd' => $amountUsd,
            'credits_micro_usd' => (int) round($amountUsd * 1_000_000),
            'currency' => 'USD',
            'status' => CreditPurchase::STATUS_PENDING,
            'idempotency_key' => 'purchase:placeholder:'.$provider.':'.$ref,
        ];
    }

    /** Build for an existing account and align the idempotency key to it. */
    public function forAccount(Account $account): static
    {
        return $this->state(function (array $attributes) use ($account): array {
            return [
                'account_id' => $account->id,
                'idempotency_key' => IdempotencyKey::forPurchase(
                    $account->id,
                    $attributes['provider'],
                    $attributes['provider_ref'],
                ),
            ];
        });
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => CreditPurchase::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}
