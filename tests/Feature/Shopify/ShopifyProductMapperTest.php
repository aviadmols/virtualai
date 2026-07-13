<?php

namespace Tests\Feature\Shopify;

use App\Domain\Products\PersistProduct;
use App\Domain\Scan\Review\ScanReview;
use App\Domain\Scan\ScanConstants;
use App\Domain\Shopify\Products\ShopifyProductMapper;
use App\Models\Product;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShopifyProductMapper — the Admin-API node becomes the SAME MappedProduct bag the PDP
 * scanner produces, with one defining difference: nothing is guessed, so every field
 * carries {confidence: 1.0, source: shopify}.
 *
 * The load-bearing assertion of this file is the LAST one: an imported product's
 * ConfirmGate is OPEN with zero reviewed rows. If a mapper change ever emits a
 * low-confidence (or undetected) field, that test goes red — which is exactly the
 * signal we want, because it would mean the merchant's friction-free "Confirm all"
 * silently stopped working.
 */
class ShopifyProductMapperTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    public function test_every_mapped_field_is_authoritative_confidence_one_from_shopify(): void
    {
        $mapped = app(ShopifyProductMapper::class)->map($this->productNode(), self::SHOP);

        foreach (['name', 'description', 'product_type', 'price', 'main_image_url', 'images'] as $field) {
            $this->assertSame(1.0, $mapped->fieldConfidence($field), "field {$field} is not authoritative");
            $this->assertSame(ScanConstants::SOURCE_SHOPIFY, $mapped->fields[$field]['source']);
        }

        $this->assertSame(1.0, $mapped->confidence);
        $this->assertSame('Merino Crew Sweater', $mapped->value('name'));
        $this->assertSame('Sweaters', $mapped->value('product_type'));
        $this->assertSame('A soft merino crew neck.', $mapped->value('description'));
    }

    public function test_money_is_integer_minor_units_with_range_and_sale_detection(): void
    {
        $mapped = app(ShopifyProductMapper::class)->map($this->productNode(), self::SHOP);
        $attributes = $mapped->toProductAttributes();

        $this->assertSame(4990, $attributes['price_minor']);      // 49.90 EUR -> minor
        $this->assertSame('EUR', $attributes['currency']);
        $this->assertTrue($attributes['price_is_range']);          // 49.90 .. 59.90
        $this->assertSame(4990, $attributes['sale_price_minor']);  // compareAt 69.90 > 49.90
        $this->assertSame(6990, $attributes['regular_price_minor']);
        $this->assertIsInt($attributes['price_minor']);            // never a float
    }

    public function test_variants_carry_the_gid_as_the_upsert_key_and_the_option_map(): void
    {
        $mapped = app(ShopifyProductMapper::class)->map($this->productNode(), self::SHOP);

        $this->assertCount(2, $mapped->variantRows);

        $first = $mapped->variantRows[0];
        $this->assertSame(self::VARIANT_A1, $first['external_id']);
        $this->assertSame(['Size' => 'S', 'Material' => 'Merino wool'], $first['options']);
        $this->assertSame(1, $first['position']);
        $this->assertSame(4990, $first['price_minor']);
        $this->assertSame('MC-S', $first['sku']);
        $this->assertTrue($first['available']);
    }

    public function test_shopifys_default_title_placeholder_never_becomes_a_fake_option(): void
    {
        $node = $this->productNode([
            'options' => [['name' => 'Title', 'position' => 1, 'values' => ['Default Title']]],
            'variants' => ['nodes' => [[
                'id' => self::VARIANT_A1,
                'title' => 'Default Title',
                'sku' => 'ONE',
                'position' => 1,
                'availableForSale' => true,
                'price' => '20.00',
                'compareAtPrice' => null,
                'selectedOptions' => [['name' => 'Title', 'value' => 'Default Title']],
                'image' => null,
            ]]],
        ]);

        $mapped = app(ShopifyProductMapper::class)->map($node, self::SHOP);

        $this->assertSame([], $mapped->variantAxes);
        $this->assertSame([], $mapped->variantRows[0]['options']);
    }

    public function test_materials_come_from_a_real_material_axis_not_a_keyword_guess(): void
    {
        $mapped = app(ShopifyProductMapper::class)->map($this->productNode(), self::SHOP);

        $this->assertSame(
            ['materials' => ['Merino wool']],
            $mapped->dimensions,
        );

        // A product with no material axis reports NO materials (never an invented one).
        $plain = app(ShopifyProductMapper::class)->map(
            $this->productNode(['options' => [['name' => 'Size', 'position' => 1, 'values' => ['S']]]]),
            self::SHOP,
        );
        $this->assertSame([], $plain->dimensions);
    }

    public function test_selectors_are_the_platform_defaults_from_config_never_a_literal(): void
    {
        $mapped = app(ShopifyProductMapper::class)->map($this->productNode(), self::SHOP);

        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $this->assertArrayHasKey($role, $mapped->detectedSelectors);
            $this->assertSame(config('shopify.selectors.'.$role), $mapped->detectedSelectors[$role]['primary']);
            $this->assertSame(1.0, $mapped->detectedSelectors[$role]['confidence']);
            // We never verified them against a live page — no invented match count.
            $this->assertNull($mapped->detectedSelectors[$role]['matched_count']);
        }
    }

    public function test_source_url_prefers_the_published_url_and_falls_back_to_the_handle(): void
    {
        $mapper = app(ShopifyProductMapper::class);

        $published = $mapper->origin($this->productNode(), self::SHOP);
        $this->assertSame('https://northstead.com/products/merino-crew', $published->url);
        $this->assertSame(self::GID_A, $published->gid);
        $this->assertSame('merino-crew', $published->handle);

        // An unpublished product has no onlineStoreUrl — the url is synthesised, never empty
        // (products.source_url is NOT NULL).
        $unpublished = $mapper->origin($this->productNode(['onlineStoreUrl' => null]), self::SHOP);
        $this->assertSame('https://'.self::SHOP.'/products/merino-crew', $unpublished->url);
    }

    /**
     * THE ONE THAT MATTERS: an imported product is confirmable with zero reviewed rows.
     * If the mapper ever emits a low/undetected blocking field, this goes red.
     */
    public function test_an_imported_product_never_blocks_the_confirm_gate(): void
    {
        [$account, $site] = $this->connectedShop();
        $mapper = app(ShopifyProductMapper::class);
        $node = $this->productNode();

        $product = Tenant::run($account, fn (): Product => app(PersistProduct::class)
            ->persist($site, $mapper->map($node, self::SHOP), $mapper->origin($node, self::SHOP)->toOrigin())
            ->product);

        $review = Tenant::run($account, fn (): ScanReview => ScanReview::fromProduct($product->fresh()));

        $this->assertTrue($review->gate->canConfirm, 'an imported product must never block confirm');
        $this->assertSame([], $review->gate->blockingKeys);
    }

    /** A Shopify product with no description / no product type is still confirmable. */
    public function test_a_product_with_no_description_or_type_is_still_confirmable(): void
    {
        [$account, $site] = $this->connectedShop();
        $mapper = app(ShopifyProductMapper::class);
        $node = $this->productNode(['description' => null, 'descriptionHtml' => '', 'productType' => '']);

        $product = Tenant::run($account, fn (): Product => app(PersistProduct::class)
            ->persist($site, $mapper->map($node, self::SHOP), $mapper->origin($node, self::SHOP)->toOrigin())
            ->product);

        $review = Tenant::run($account, fn (): ScanReview => ScanReview::fromProduct($product->fresh()));

        $this->assertTrue($review->gate->canConfirm);
    }
}
