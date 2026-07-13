<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Product;
use App\Models\ShopifyMediaSnapshot;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyMediaSnapshot>
 *
 * Sets account_id / site_id explicitly so the row builds without a bound tenant. Use
 * forProduct() to build a COHERENT snapshot (one account across product + snapshot) — a
 * chained factory would otherwise mint a foreign account (TS-TENANCY-005).
 */
class ShopifyMediaSnapshotFactory extends Factory
{
    protected $model = ShopifyMediaSnapshot::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'product_id' => Product::factory(),
            'external_id' => 'gid://shopify/Product/1001',
            'status' => ShopifyMediaSnapshot::STATUS_CAPTURING,
            'media' => [],
        ];
    }

    /** A coherent snapshot: the product's account/site + its GID. */
    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'account_id' => $product->account_id,
            'site_id' => $product->site_id,
            'product_id' => $product->getKey(),
            'external_id' => $product->external_id,
        ]);
    }

    /** @param array<int,array<string,mixed>> $media */
    public function captured(array $media = []): static
    {
        return $this->state(fn () => [
            'status' => ShopifyMediaSnapshot::STATUS_CAPTURED,
            'media' => $media,
            'captured_at' => now(),
        ]);
    }
}
