<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds one draft Product on Store A (account 1, site 1) with a DELIBERATE mix of
 * field-confidence levels (high / medium / low / not_detected) and detected
 * selectors (single-match / multi-match / not-found) — so the A4 scan-review form
 * screenshots show the full row-state matrix and the blocked confirm gate.
 *
 * Idempotent: re-running deletes the prior demo product first. Demo/visual only.
 */
class ScanReviewDemoSeeder extends Seeder
{
    // === CONSTANTS ===
    private const ACCOUNT_ID = 1;
    private const SITE_ID = 1;
    private const SOURCE_URL = 'https://store-a.test/products/demo-tee';

    public function run(): void
    {
        Tenant::run(self::ACCOUNT_ID, function (): void {
            $site = Site::findOrFail(self::SITE_ID);

            Product::where('site_id', $site->id)
                ->where('source_url', self::SOURCE_URL)
                ->get()
                ->each(fn (Product $p) => $p->variants()->delete() || $p->delete());

            Product::where('site_id', $site->id)
                ->where('source_url', self::SOURCE_URL)
                ->delete();

            $product = Product::create([
                'site_id' => $site->id,
                'source_url' => self::SOURCE_URL,
                'source_url_hash' => sha1(self::SOURCE_URL),
                'status' => Product::STATUS_DRAFT,
                'name' => 'Aviad Linen Tee',
                'description' => 'A relaxed-fit linen tee in stonewashed sand.',
                'product_type' => 'apparel',
                'price_minor' => 8900,
                'currency' => 'USD',
                'main_image_url' => 'https://cdn.example.com/demo-tee.jpg',
                'images' => [],
                'physical_dimensions' => ['length' => '72cm', 'chest' => '54cm'],
                'confidence' => 0.62,
                'field_confidence' => [
                    'name' => ['value' => 'Aviad Linen Tee', 'confidence' => 0.93, 'source' => 'jsonld'],
                    'price' => ['value' => '89.00', 'confidence' => 0.81, 'source' => 'jsonld'],
                    'description' => ['value' => 'A relaxed-fit linen tee in stonewashed sand.', 'confidence' => 0.55, 'source' => 'dom'],
                    'product_type' => ['value' => 'apparel', 'confidence' => 0.30, 'source' => 'model_inferred'],
                    'main_image_url' => ['value' => null, 'confidence' => null, 'source' => null],
                ],
                'detected_selectors' => [
                    'add_to_cart' => ['primary' => 'button.add-to-cart', 'matched_count' => 1, 'confidence' => 0.92, 'strategy' => 'class', 'fallback_chain' => [], 'needs_review' => false],
                    'product_image' => ['primary' => 'img.product-photo', 'matched_count' => 3, 'confidence' => 0.40, 'strategy' => 'class', 'fallback_chain' => [], 'needs_review' => true],
                    'title' => ['primary' => 'h1.product-title', 'matched_count' => 1, 'confidence' => 0.88, 'strategy' => 'semantic', 'fallback_chain' => [], 'needs_review' => false],
                    'price' => ['primary' => '.price ins .amount', 'matched_count' => 1, 'confidence' => 0.74, 'strategy' => 'class', 'fallback_chain' => [], 'needs_review' => false],
                    'description' => ['primary' => null, 'matched_count' => 0, 'confidence' => null, 'strategy' => null, 'fallback_chain' => [], 'needs_review' => true],
                    'variations' => ['primary' => 'select#variant', 'matched_count' => 1, 'confidence' => 0.66, 'strategy' => 'id', 'fallback_chain' => [], 'needs_review' => false],
                ],
            ]);

            ProductVariant::create(['product_id' => $product->id, 'account_id' => self::ACCOUNT_ID, 'options' => ['size' => 'S'], 'sku' => 'TEE-S', 'price_minor' => 8900, 'confidence' => 0.8, 'available' => true]);
            ProductVariant::create(['product_id' => $product->id, 'account_id' => self::ACCOUNT_ID, 'options' => ['size' => 'M'], 'sku' => 'TEE-M', 'price_minor' => 8900, 'confidence' => 0.8, 'available' => true]);

            $this->command?->info("Seeded scan-review demo product id={$product->id} on site {$site->id}.");
        });
    }
}
