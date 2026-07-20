<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\MentionResolver;
use App\Domain\Ai\MentionTags;
use App\Domain\Generation\ProductFacts;
use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MentionResolver — the @-mention -> reference-image foundation shared by the try-on prompt
 * editor, Image Studio and Banners.
 *
 * Proves: a @product_{id} attaches that product's image; a metafield token that shares the
 * "product_" prefix (@product_details) is NEVER read as an entity id; resolution is tenant +
 * site scoped and FAIL-CLOSED (a foreign product, an imageless/bad-url product, or no bound
 * tenant all contribute nothing); and the reference count is capped.
 */
class MentionResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): MentionResolver
    {
        return app(MentionResolver::class);
    }

    public function test_a_product_tag_resolves_to_its_image(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $product = Product::factory()->forSite($site)->create([
            'main_image_url' => 'https://cdn.example.com/a.jpg',
        ]);

        $images = Tenant::run($account, fn (): array => $this->resolver()
            ->referenceImages("Make it look great @product_{$product->id} please", $site));

        $this->assertCount(1, $images);
        $this->assertSame('https://cdn.example.com/a.jpg', $images[0]->url);
        $this->assertTrue($images[0]->isRemote);
    }

    public function test_a_metafield_token_sharing_the_prefix_is_not_read_as_an_entity(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        Product::factory()->forSite($site)->create();

        // @product_details / @product_type / @materials are METAFIELDS, not entities: no images.
        $images = Tenant::run($account, fn (): array => $this->resolver()
            ->referenceImages('Use @product_details and @product_type and @materials', $site));

        $this->assertSame([], $images);
    }

    public function test_a_foreign_accounts_product_resolves_to_nothing(): void
    {
        $accountA = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();

        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create();
        $productB = Product::factory()->forSite($siteB)->create([
            'main_image_url' => 'https://cdn.example.com/secret.jpg',
        ]);

        // A is trying to attach B's product by id — the site filter AND the account scope block it.
        $images = Tenant::run($accountA, fn (): array => $this->resolver()
            ->referenceImages("steal @product_{$productB->id}", $siteA));

        $this->assertSame([], $images);
    }

    public function test_the_account_scope_is_the_wall_even_for_the_products_own_site(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create();
        $productB = Product::factory()->forSite($siteB)->create([
            'main_image_url' => 'https://cdn.example.com/secret.jpg',
        ]);

        // Even passing B's OWN site, the bound tenant is A: the fail-closed global scope
        // (account_id = A) excludes B's product. Isolation does not depend on the site filter.
        $images = Tenant::run($accountA, fn (): array => $this->resolver()
            ->referenceImages("@product_{$productB->id}", $siteB));

        $this->assertSame([], $images);
    }

    public function test_an_imageless_or_bad_url_product_is_skipped(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $noImage = Product::factory()->forSite($site)->create(['main_image_url' => null]);
        $badUrl = Product::factory()->forSite($site)->create(['main_image_url' => 'ftp://nope/x.jpg']);

        $images = Tenant::run($account, fn (): array => $this->resolver()
            ->referenceImages("@product_{$noImage->id} @product_{$badUrl->id}", $site));

        $this->assertSame([], $images);
    }

    public function test_the_reference_count_is_capped(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $tags = [];
        foreach (range(1, MentionTags::MAX_REFERENCES + 3) as $i) {
            $product = Product::factory()->forSite($site)->create([
                'main_image_url' => "https://cdn.example.com/{$i}.jpg",
            ]);
            $tags[] = "@product_{$product->id}";
        }

        $images = Tenant::run($account, fn (): array => $this->resolver()
            ->referenceImages(implode(' ', $tags), $site));

        $this->assertCount(MentionTags::MAX_REFERENCES, $images);
    }

    public function test_with_no_bound_tenant_it_fails_closed_to_nothing(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $product = Product::factory()->forSite($site)->create([
            'main_image_url' => 'https://cdn.example.com/a.jpg',
        ]);

        // No Tenant::run: the fail-closed scope constrains to an impossible account => nothing.
        $images = $this->resolver()->referenceImages("@product_{$product->id}", $site);

        $this->assertSame([], $images);
    }

    public function test_tokens_are_unicode_aware_and_deduped(): void
    {
        $tokens = MentionResolver::tokens('hello @product_5 שלום @חולצה again @product_5 @materials');

        $this->assertSame(['product_5', 'חולצה', 'materials'], $tokens);
    }

    public function test_the_metafield_catalog_matches_product_facts(): void
    {
        $tokens = $this->resolver()->metafieldTokens();

        $this->assertContains(ProductFacts::VAR_MATERIALS, $tokens);
        $this->assertContains(ProductFacts::VAR_PRODUCT_DETAILS, $tokens);
        $this->assertContains(MentionTags::METAFIELD_PRODUCT_NAME, $tokens);
        // The identity + every ProductFacts var, nothing shopper-specific (no height/variant).
        $this->assertNotContains('height', $tokens);
        $this->assertNotContains('variant', $tokens);
    }
}
