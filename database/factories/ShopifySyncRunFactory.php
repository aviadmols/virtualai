<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ShopifySyncRun;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifySyncRun>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant, and
 * offers forSite() for a coherent site+account pair (TS-TENANCY-005: never let a nested
 * factory mint a foreign account inside Tenant::run).
 */
class ShopifySyncRunFactory extends Factory
{
    protected $model = ShopifySyncRun::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'mode' => ShopifySyncRun::MODE_CATALOG,
            'status' => ShopifySyncRun::STATUS_PENDING,
            'correlation_id' => 'sync_'.fake()->bothify('??????######'),
        ];
    }

    /** Build the run for an existing site (and its account). */
    public function forSite(Site $site): static
    {
        return $this->state(fn (): array => [
            'account_id' => $site->account_id,
            'site_id' => $site->id,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ShopifySyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /** @param array<int,string> $gids */
    public function selection(array $gids): static
    {
        return $this->state(fn (): array => [
            'mode' => ShopifySyncRun::MODE_SELECTION,
            'requested_gids' => $gids,
        ]);
    }
}
