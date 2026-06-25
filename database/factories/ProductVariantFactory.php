<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'product_id' => Product::factory(),
            'options' => ['color' => fake()->safeColorName(), 'size' => fake()->randomElement(['S', 'M', 'L'])],
            'price_minor' => fake()->numberBetween(1000, 50000),
            'image_url' => 'https://cdn.example.com/'.fake()->uuid().'.jpg',
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'available' => true,
            'confidence' => 0.8,
        ];
    }

    /** Build for an existing product (keeps account_id consistent). */
    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'account_id' => $product->account_id,
            'product_id' => $product->id,
        ]);
    }
}
