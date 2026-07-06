<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Banner;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Banner>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant.
 */
class BannerFactory extends Factory
{
    protected $model = Banner::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'name' => 'Summer Sale',
            'status' => Banner::STATUS_DRAFT,
            'composition' => Banner::COMPOSITION_IMAGE,
        ];
    }

    /** Build the banner under a specific site (and its account). */
    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'account_id' => $site->account_id,
            'site_id' => $site->getKey(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => Banner::STATUS_ACTIVE]);
    }
}
