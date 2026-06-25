<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        // The money columns are intentionally absent: they are NOT mass-assignable
        // (S4) and start at 0 via the model's $attributes default; the opening $5
        // grant is then written by AccountObserver through the ledger writer.
        return [
            'name' => fake()->company(),
            'status' => Account::STATUS_ACTIVE,
            'locale' => Account::DEFAULT_LOCALE,
            'billing_email' => fake()->unique()->companyEmail(),
            'company_name' => fake()->company(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Account::STATUS_SUSPENDED]);
    }
}
