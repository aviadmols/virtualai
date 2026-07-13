<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAsset>
 *
 * Sets account_id / site_id explicitly so the row builds without a bound tenant. Use
 * forProduct() to build a COHERENT asset (one account across product + batch + asset) —
 * a chained factory would otherwise mint a foreign account (TS-TENANCY-005).
 */
class ProductAssetFactory extends Factory
{
    protected $model = ProductAsset::class;

    private const SOURCE_URL = 'https://cdn.example.com/product-main.jpg';

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'product_id' => Product::factory(),
            'batch_id' => ProductImageBatch::factory(),
            'operation_key' => AiOperation::KEY_PACKSHOT_GENERATION,
            'status' => ProductAsset::STATUS_PENDING,
            'review_status' => ProductAsset::REVIEW_AWAITING,
            'client_request_id' => ProductAsset::REQUEST_BATCH,
            'idempotency_key' => 'product_asset:'.Str::random(40),
            'source_image_url' => self::SOURCE_URL,
            'source_image_hash' => sha1(self::SOURCE_URL),
        ];
    }

    /** A coherent asset: the product's account/site, under the given batch. */
    public function forProduct(Product $product, ProductImageBatch $batch): static
    {
        return $this->state(fn () => [
            'account_id' => $product->account_id,
            'site_id' => $product->site_id,
            'product_id' => $product->getKey(),
            'batch_id' => $batch->getKey(),
            'operation_key' => $batch->operation_key,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => ProductAsset::STATUS_PROCESSING]);
    }

    /** A finished, reviewable asset (an image exists). */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => ProductAsset::STATUS_SUCCEEDED,
            'review_status' => ProductAsset::REVIEW_AWAITING,
            'image_path' => 'accounts/1/sites/1/product-assets/1/result-'.Str::random(8).'.png',
            'image_mime' => 'image/png',
        ]);
    }

    /** A succeeded + APPROVED asset — the only kind that may be pushed to the store. */
    public function approved(): static
    {
        return $this->succeeded()->state(fn () => [
            'review_status' => ProductAsset::REVIEW_APPROVED,
        ]);
    }
}
