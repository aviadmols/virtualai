<?php

namespace Tests\Feature\Widget;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The add-to-cart half of the bootstrap payload: the widget cannot call Shopify's
 * /cart/add.js with OUR internal variant key, so the payload ships the NUMERIC Shopify
 * variant id (the GID's tail) plus the product's source rail.
 *
 * The id is public by construction (it is already in the merchant's own
 * <form action="/cart/add"> on the same page). These tests also pin the other half of that
 * claim: NOTHING else new rides out on the payload.
 */
final class WidgetCartPayloadTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    // === CONSTANTS ===
    private const SHOPIFY_VARIANT_GID = 'gid://shopify/ProductVariant/44556677889';

    private const SHOPIFY_VARIANT_NUMERIC = '44556677889';

    private const SHOPIFY_PRODUCT_GID = 'gid://shopify/Product/999888777';

    private const PDP_URL = 'https://shop.example.com/products/shopify-tee';

    // Not a Shopify GID — an internal value that must never ride out as a public cart id.
    private const NON_GID_EXTERNAL_ID = 'legacy-internal-ref-42';

    // Internals that must never appear on a public storefront payload.
    private const FORBIDDEN_PRODUCT_KEYS = [
        'account_id', 'site_id', 'scan_raw', 'detected_selectors', 'source_url',
        'confidence', 'field_confidence', 'external_id',
    ];

    private const FORBIDDEN_VARIANT_KEYS = ['account_id', 'product_id', 'confidence', 'is_active'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_bootstrap_ships_the_numeric_shopify_variant_id_for_a_shopify_product(): void
    {
        $ctx = $this->makeSiteContext();
        $variant = $this->shopifyProduct($ctx['site']);

        $payload = $this->bootstrap($ctx);

        $this->assertSame(Product::SOURCE_SHOPIFY, $payload['product']['source']);

        $shipped = $payload['product']['variants'][0]['external_id'];

        // The NUMERIC id the AJAX cart wants — not the GID, and not our DB key.
        $this->assertSame(self::SHOPIFY_VARIANT_NUMERIC, $shipped);
        $this->assertNotSame(self::SHOPIFY_VARIANT_GID, $shipped);
        $this->assertNotSame((string) $variant->getKey(), $shipped);

        // Our internal key is still there (the generate call is keyed by it) — both, distinctly.
        $this->assertSame($variant->getKey(), $payload['product']['variants'][0]['id']);
    }

    public function test_a_scanned_product_variant_has_a_null_external_id(): void
    {
        // makeSiteContext builds a SCANNED product + variant (no Shopify GID anywhere).
        $ctx = $this->makeSiteContext();

        $payload = $this->bootstrap($ctx, 'https://shop.example.com/p/red-sneaker');

        $this->assertSame(Product::SOURCE_SCAN, $payload['product']['source']);
        $this->assertNull($payload['product']['variants'][0]['external_id']);
    }

    /**
     * Only a well-formed ProductVariant GID becomes an external_id. A scan-era variant whose
     * external_id holds some other internal string must ship NULL — the payload exposes the
     * PUBLIC Shopify id, not "whatever is in that column".
     */
    public function test_a_non_gid_external_id_is_never_shipped(): void
    {
        $ctx = $this->makeSiteContext();

        Tenant::run($ctx['site']->account, function () use ($ctx): void {
            $product = Product::factory()->forSite($ctx['site'])->confirmed()->create([
                'source' => Product::SOURCE_SCAN,
                'source_url' => self::PDP_URL,
                'source_url_hash' => sha1(self::PDP_URL),
            ]);

            ProductVariant::factory()->forProduct($product)->create([
                'external_id' => self::NON_GID_EXTERNAL_ID,
                'options' => ['size' => 'L'],
            ]);
        });

        $payload = $this->bootstrap($ctx);

        $shipped = $payload['product']['variants'][0]['external_id'];

        $this->assertNull($shipped);
        $this->assertNotSame(self::NON_GID_EXTERNAL_ID, $shipped);
    }

    public function test_the_payload_leaks_no_new_internals(): void
    {
        $ctx = $this->makeSiteContext();
        $this->shopifyProduct($ctx['site']);

        $payload = $this->bootstrap($ctx);

        foreach (self::FORBIDDEN_PRODUCT_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload['product'], "product.$key must never be public");
        }

        foreach (self::FORBIDDEN_VARIANT_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload['product']['variants'][0], "variant.$key must never be public");
        }
    }

    /** GET the bootstrap for a PDP url and return the decoded body. */
    private function bootstrap(array $ctx, string $url = self::PDP_URL): array
    {
        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/bootstrap?url='.urlencode($url));

        $response->assertOk();

        return $response->json();
    }

    /** A CONFIRMED, Shopify-sourced product on the site, with one GID-carrying variant. */
    private function shopifyProduct(Site $site): ProductVariant
    {
        return Tenant::run($site->account, function () use ($site): ProductVariant {
            $product = Product::factory()->forSite($site)->confirmed()->create([
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => self::SHOPIFY_PRODUCT_GID,
                'source_url' => self::PDP_URL,
                'source_url_hash' => sha1(self::PDP_URL),
                'name' => 'Shopify Tee',
            ]);

            return ProductVariant::factory()->forProduct($product)->create([
                'external_id' => self::SHOPIFY_VARIANT_GID,
                'options' => ['size' => 'M'],
            ]);
        });
    }
}
