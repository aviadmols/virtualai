<?php

namespace Tests\Feature\Widget;

use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The widget bootstrap resolves a CONFIRMED Shopify product by the /products/{handle} in
 * the shopper's LIVE url — not only an exact source_url_hash. A shopper's url differs from
 * the synthesized storefront url (custom domain, ?variant=, locale prefix, trailing slash,
 * UTM), so a Shopify site matches on external_handle. Without this the theme-slot button
 * mounts but the try-on can't run (product: null). A non-handle url still resolves nothing.
 */
final class WidgetShopifyProductMatchTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    private const ORIGIN = 'https://custom-shop.com';

    private const HANDLE = 'blue-linen-shirt';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_a_shopify_product_resolves_by_handle_for_a_differing_shopper_url(): void
    {
        [, $site, $product] = $this->shopifyShopWithProduct();

        // The shopper's live url: custom domain + ?variant + UTM — NOT the stored myshopify url.
        $url = self::ORIGIN.'/products/'.self::HANDLE.'?variant=99&utm_source=x';

        $response = $this->withHeaders($this->widgetHeaders($site, self::ORIGIN))
            ->getJson('/widget/v1/bootstrap?url='.urlencode($url));

        $response->assertOk();
        $this->assertSame($product->id, $response->json('product.id'));
    }

    public function test_a_url_without_a_product_handle_resolves_no_product(): void
    {
        [, $site] = $this->shopifyShopWithProduct();

        $response = $this->withHeaders($this->widgetHeaders($site, self::ORIGIN))
            ->getJson('/widget/v1/bootstrap?url='.urlencode(self::ORIGIN.'/pages/about'));

        $response->assertOk();
        $this->assertNull($response->json('product'));
    }

    /** A confirmed Shopify product whose STORED url differs from the shopper's live url. */
    private function shopifyShopWithProduct(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create([
            'platform' => Site::PLATFORM_SHOPIFY,
            'allowed_origins' => [self::ORIGIN],
            'free_generations_before_signup' => 2,
        ]);

        $product = Tenant::run($account, function () use ($site): Product {
            $product = Product::factory()->forSite($site)->confirmed()->create([
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => 'gid://shopify/Product/555',
                'external_handle' => self::HANDLE,
                'source_url' => 'https://lets-sell.myshopify.com/products/'.self::HANDLE,
                'source_url_hash' => sha1('https://lets-sell.myshopify.com/products/'.self::HANDLE),
                'main_image_url' => 'https://cdn.example.com/blue.jpg',
            ]);
            ProductVariant::factory()->forProduct($product)->create(['options' => ['size' => 'M']]);

            return $product;
        });

        return [$account, $site, $product];
    }
}
