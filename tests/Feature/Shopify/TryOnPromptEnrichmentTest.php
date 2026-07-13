<?php

namespace Tests\Feature\Shopify;

use App\Domain\Generation\ProductFacts;
use App\Domain\Products\PersistProduct;
use App\Domain\Shopify\Products\ShopifyProductMapper;
use App\Models\AiOperation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Prompt;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Try-on prompt enrichment — the real product data finally reaches the model.
 *
 * Until Phase 3 only name / type / variant / height were substituted; description,
 * materials, the option map and the measured dimensions were persisted at scan time and
 * then ignored. ProductFacts exposes each as its own placeholder plus a composed
 * {{product_details}} clause, and the seeded platform prompts reference it.
 *
 * The subtle law here: an UNKNOWN fact contributes NOTHING — never an empty sentence
 * ("It is made of ."), which would poison the prompt of every sparse product.
 */
class TryOnPromptEnrichmentTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    public function test_the_seeded_platform_try_on_prompts_reference_the_product_details_clause(): void
    {
        $this->seed(AiControlPlaneSeeder::class);

        $prompts = Prompt::query()
            ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->whereNull('account_id')
            ->get();

        $this->assertNotEmpty($prompts, 'the try-on prompts must be seeded');

        foreach ($prompts as $prompt) {
            $this->assertStringContainsString('{{product_details}}', (string) $prompt->user_prompt, "scope={$prompt->scope} was not enriched");
            // The framing clause must stay LAST (it governs the output geometry).
            $this->assertStringContainsString('Return the image at the SAME orientation', (string) $prompt->user_prompt);
        }
    }

    public function test_facts_from_a_shopify_product_compose_the_details_clause(): void
    {
        [$account, $site] = $this->connectedShop();

        $mapper = app(ShopifyProductMapper::class);
        $node = $this->productNode();

        $product = Tenant::run($account, fn (): Product => app(PersistProduct::class)->persist(
            $site,
            $mapper->map($node, self::SHOP),
            $mapper->origin($node, self::SHOP)->toOrigin(),
        )->product);

        $variant = Tenant::run($account, fn (): ProductVariant => ProductVariant::query()
            ->where('external_id', self::VARIANT_A1)
            ->firstOrFail());

        $facts = ProductFacts::for($product->fresh(), $variant);
        $vars = $facts->toVars();

        $this->assertSame('Merino wool', $vars[ProductFacts::VAR_MATERIALS]);
        $this->assertSame('Size: S, Material: Merino wool', $vars[ProductFacts::VAR_OPTIONS]);
        $this->assertSame('A soft merino crew neck.', $vars[ProductFacts::VAR_DESCRIPTION]);

        $details = $vars[ProductFacts::VAR_PRODUCT_DETAILS];
        $this->assertStringContainsString('Selected options: Size: S, Material: Merino wool.', $details);
        $this->assertStringContainsString('The item is made of Merino wool.', $details);
        $this->assertStringContainsString('Product description: A soft merino crew neck.', $details);
    }

    public function test_an_unknown_fact_contributes_nothing_never_an_empty_sentence(): void
    {
        $bare = Product::factory()->make([
            'description' => null,
            'physical_dimensions' => [],
        ]);

        $facts = ProductFacts::for($bare, null);

        $this->assertSame('', $facts->productDetails(), 'a product with no facts must add NOTHING to the prompt');
        $this->assertSame('', $facts->materials);
        $this->assertSame('', $facts->options);
        $this->assertSame('', $facts->dimensions);
    }

    public function test_html_is_stripped_and_the_description_is_bounded(): void
    {
        $product = Product::factory()->make([
            'description' => '<p>Soft &amp; warm.</p><script>alert(1)</script>'.str_repeat('x', 900),
        ]);

        $facts = ProductFacts::for($product, null);

        $this->assertStringNotContainsString('<', $facts->description);
        $this->assertStringNotContainsString('script', $facts->description);
        $this->assertLessThan(600, strlen($facts->description), 'marketing copy must not flood the image prompt');
    }

    public function test_a_nested_dimension_group_is_skipped_not_stringified(): void
    {
        // TS-PDPSCAN-007: a nested group (the merchant's visual picks / a size map) blew
        // up naive rendering. Only SCALAR measurements reach the prompt.
        $product = Product::factory()->make([
            'physical_dimensions' => [
                'chest' => '100 cm',
                'picks' => ['size' => ['selector' => '.size', 'value' => 'M']],
                'size_map' => ['S' => ['chest' => 96]],
                'materials' => ['cotton'],
            ],
        ]);

        $facts = ProductFacts::for($product, null);

        $this->assertSame('chest: 100 cm', $facts->dimensions);
        $this->assertSame('cotton', $facts->materials);
    }
}
