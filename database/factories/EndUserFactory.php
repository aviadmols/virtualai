<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EndUser>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant.
 * The anon_token is unique within a site (the free-tries counter follows it).
 */
class EndUserFactory extends Factory
{
    protected $model = EndUser::class;

    public function definition(): array
    {
        $site = Site::factory();

        return [
            'account_id' => Account::factory(),
            'site_id' => $site,
            'anon_token' => 'anon_'.Str::random(32),
            'status' => EndUser::STATUS_NEW,
            'generations_used' => 0,
        ];
    }

    /** Build the lead under a given site (and its account). */
    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'account_id' => $site->account_id,
            'site_id' => $site->id,
        ]);
    }

    /** A registered lead (signup captured). */
    public function registered(): static
    {
        return $this->state(fn () => [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'registered_at' => now(),
        ]);
    }

    /** A lead that has already used $count free generations. */
    public function withGenerationsUsed(int $count): static
    {
        return $this->state(fn () => ['generations_used' => $count]);
    }
}
