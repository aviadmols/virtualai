<?php

namespace Database\Factories;

use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Generation>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant.
 * A fresh generation is always status=pending; the idempotency key is derived from
 * the deterministic IdempotencyKey so the unique index behaves like production.
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    public function definition(): array
    {
        $clientRequestId = 'crq_'.Str::random(16);

        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'end_user_id' => EndUser::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'status' => Generation::STATUS_PENDING,
            'client_request_id' => $clientRequestId,
            'idempotency_key' => 'generation:'.Str::random(40).':'.$clientRequestId,
            'meta' => ['user_height' => 175],
        ];
    }

    /**
     * Build a coherent generation for a real lead + product + variant under one
     * account/site, with the production-shaped idempotency key.
     */
    public function forContext(EndUser $endUser, Product $product, ProductVariant $variant, string $clientRequestId = 'crq_test'): static
    {
        return $this->state(fn () => [
            'account_id' => $endUser->account_id,
            'site_id' => $endUser->site_id,
            'end_user_id' => $endUser->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'client_request_id' => $clientRequestId,
            'idempotency_key' => IdempotencyKey::forGeneration(
                accountId: (int) $endUser->account_id,
                siteId: (int) $endUser->site_id,
                endUserId: (int) $endUser->id,
                productId: (int) $product->id,
                variant: (array) ($variant->options ?? []),
                clientRequestId: $clientRequestId,
            ),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => Generation::STATUS_PROCESSING]);
    }
}
