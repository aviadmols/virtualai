<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 *
 * Sets account_id + site_id explicitly so the model creating hook respects them
 * without a bound tenant (factory builds). A fresh scan is always status=draft.
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $url = 'https://'.fake()->domainName().'/products/'.fake()->slug();

        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'source_url' => $url,
            'source_url_hash' => sha1($url),
            'status' => Product::STATUS_DRAFT,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'product_type' => 'apparel',
            'price_minor' => fake()->numberBetween(1000, 50000),
            'currency' => 'USD',
            'main_image_url' => 'https://cdn.example.com/'.fake()->uuid().'.jpg',
            'images' => [],
            'field_confidence' => [],
            'detected_selectors' => [],
            'confidence' => 0.8,
        ];
    }

    /** Build for an existing account + site (keeps account_id consistent). */
    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'account_id' => $site->account_id,
            'site_id' => $site->id,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => Product::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }
}
