<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\ScanConstants;
use App\Domain\Scan\ScanProductJob;
use App\Exceptions\CrossTenantWriteException;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adversarial tenant-isolation spot-check on the Phase-4 Product / ProductVariant
 * models + ScanProductJob. Release-blocker-class: proves account B can never read
 * account A's products or variants (find / where / relation traversal / child-only
 * query), the write path stamps the JOB's explicit account_id (TS-TENANCY-001
 * back-to-back), the cross-tenant write guard cannot be bypassed by an explicit id,
 * neither model escaped the global scope onto the allow-list, and an unbound query
 * fails closed.
 */
class ProductScanIsolationSpotCheckTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const BASE = 'https://openrouter.ai/api/v1';

    private const URL_A = 'https://shop-a.example.com/products/widget-a';

    private const URL_B = 'https://shop-b.example.com/products/widget-b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::BASE);
        config()->set('services.openrouter.timeout', 30);
    }

    // --- Point 1: cross-account read isolation (find / where / relation traversal) ---

    public function test_account_b_cannot_read_account_a_products_by_any_read_path(): void
    {
        [$accountA, $siteA, $productA] = $this->seedProductWithVariants();
        $accountB = Account::factory()->create();

        Tenant::run($accountB, function () use ($productA, $siteA) {
            // find() must respect the scope.
            $this->assertNull(Product::find($productA->id));
            // where()->first() must respect the scope.
            $this->assertNull(Product::where('id', $productA->id)->first());
            // exists() must respect the scope.
            $this->assertFalse(Product::where('id', $productA->id)->exists());
            // count() across the whole table is zero for B.
            $this->assertSame(0, Product::count());
            // site->products relation traversal (the relation also carries the scope).
            $siteAsB = Site::find($siteA->id);
            $this->assertNull($siteAsB); // the Site itself is invisible to B
        });
    }

    // --- Point 2: ProductVariant isolation in particular (child-only direct query) ---

    public function test_account_b_cannot_read_account_a_variants_via_child_only_query(): void
    {
        [$accountA, , $productA] = $this->seedProductWithVariants();
        $accountB = Account::factory()->create();

        $variantIds = Tenant::run($accountA, fn () => $productA->variants()->pluck('id')->all());
        $this->assertCount(3, $variantIds);

        Tenant::run($accountB, function () use ($variantIds) {
            // A direct variant query (NOT through the parent product) must not leak.
            $this->assertSame(0, ProductVariant::count());
            foreach ($variantIds as $id) {
                $this->assertNull(ProductVariant::find($id));
                $this->assertFalse(ProductVariant::whereKey($id)->exists());
            }
            // whereIn over A's ids returns nothing for B.
            $this->assertCount(0, ProductVariant::whereIn('id', $variantIds)->get());
        });

        // And A still sees its own four... three variants.
        Tenant::run($accountA, function () use ($variantIds) {
            $this->assertSame(count($variantIds), ProductVariant::count());
        });
    }

    public function test_variant_relation_traversal_from_a_foreign_product_is_blocked(): void
    {
        [$accountA, , $productA] = $this->seedProductWithVariants();
        $accountB = Account::factory()->create();

        // B holds a detached A-product instance (e.g. smuggled id) and traverses ->variants.
        Tenant::run($accountB, function () use ($productA) {
            $foreign = new Product;
            $foreign->id = $productA->id;
            // The HasMany is account-scoped on the variant side -> empty under B.
            $this->assertCount(0, $foreign->variants()->get());
        });
    }

    // --- Point 3: write-path tenancy (back-to-back A-scan then B-scan, one worker) ---

    public function test_back_to_back_scans_land_each_product_on_the_right_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();
        $siteB = Site::factory()->forAccount($accountB)->create();

        $this->fakeFetch();
        $this->mockExtraction();

        // Same worker process, consecutive jobs (the TS-TENANCY-001 scar shape).
        (new ScanProductJob($accountA->id, $siteA->id, self::URL_A))->handle();
        (new ScanProductJob($accountB->id, $siteB->id, self::URL_B))->handle();

        // No tenant is left bound after the jobs (run() clears in finally).
        $this->assertFalse(Tenant::check());

        $productA = Tenant::run($accountA, fn () => Product::where('site_id', $siteA->id)->first());
        $productB = Tenant::run($accountB, fn () => Product::where('site_id', $siteB->id)->first());

        $this->assertNotNull($productA);
        $this->assertNotNull($productB);
        // Each product + every variant is stamped with its OWN account, not the other's.
        $this->assertSame($accountA->id, $productA->account_id);
        $this->assertSame($accountB->id, $productB->account_id);

        $variantsA = Tenant::run($accountA, fn () => $productA->variants()->get());
        $variantsB = Tenant::run($accountB, fn () => $productB->variants()->get());
        $this->assertGreaterThan(0, $variantsA->count());
        $this->assertGreaterThan(0, $variantsB->count());
        $this->assertTrue($variantsA->every(fn (ProductVariant $v) => $v->account_id === $accountA->id));
        $this->assertTrue($variantsB->every(fn (ProductVariant $v) => $v->account_id === $accountB->id));

        // B's variants never carry A's account_id and vice versa (no bleed).
        $this->assertNotContains($accountA->id, $variantsB->pluck('account_id')->all());
        $this->assertNotContains($accountB->id, $variantsA->pluck('account_id')->all());
    }

    public function test_cross_tenant_write_mass_assign_coerces_to_bound_tenant_no_leak(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create();

        // account_id is NOT fillable (deliberate Phase-2 design), so a mass-assigned
        // foreign id is dropped and the row is auto-stamped with the BOUND tenant (B).
        // The foreign account A never receives a row -> no cross-account write, no leak.
        $product = Tenant::run($accountB, fn () => Product::create([
            'account_id' => $accountA->id, // foreign id — silently ignored (non-fillable)
            'site_id' => $siteB->id,
            'source_url' => self::URL_A,
            'source_url_hash' => sha1(self::URL_A),
            'status' => Product::STATUS_DRAFT,
        ]));

        $this->assertSame($accountB->id, $product->account_id); // stamped to the bound tenant
        Tenant::run($accountA, fn () => $this->assertSame(0, Product::count())); // nothing under A
        Tenant::run($accountB, fn () => $this->assertSame(1, Product::count())); // the row is B's
    }

    public function test_cross_tenant_write_guard_throws_on_direct_foreign_account_id(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create();

        // The direct attribute-set vector (which mass-assignment cannot reach because
        // account_id is non-fillable): a bound tenant (B) stamping a row for a DIFFERENT
        // account (A) fails loud via the creating guard and persists nothing.
        Tenant::run($accountB, function () use ($accountA, $siteB) {
            $product = new Product;
            $product->account_id = $accountA->id; // direct foreign id — reaches the guard
            $product->site_id = $siteB->id;
            $product->source_url = self::URL_A;
            $product->source_url_hash = sha1(self::URL_A);
            $product->status = Product::STATUS_DRAFT;

            try {
                $product->save();
                $this->fail('Expected CrossTenantWriteException for a direct foreign account_id.');
            } catch (CrossTenantWriteException) {
                // expected — the guard blocked the cross-tenant write
            }
        });

        // Nothing leaked into the table for either account.
        Tenant::run($accountA, fn () => $this->assertSame(0, Product::count()));
        Tenant::run($accountB, fn () => $this->assertSame(0, Product::count()));
    }

    public function test_variant_create_guard_throws_on_direct_foreign_account_id(): void
    {
        [$accountA, , $productA] = $this->seedProductWithVariants();
        $accountB = Account::factory()->create();

        // The child model enforces the same guard on the direct attribute-set vector:
        // a bound tenant (B) stamping a variant for a DIFFERENT account (A) fails loud.
        // (The mass-assignment vector is covered for Product above: account_id is
        // non-fillable, so a foreign id is coerced to the bound tenant, never leaked.)
        Tenant::run($accountB, function () use ($accountA, $productA) {
            $variant = new ProductVariant;
            $variant->account_id = $accountA->id; // direct foreign id — reaches the guard
            $variant->product_id = $productA->id;
            $variant->options = ['color' => 'Red'];
            $variant->available = true;

            try {
                $variant->save();
                $this->fail('Expected CrossTenantWriteException for a direct foreign account_id.');
            } catch (CrossTenantWriteException) {
                // expected — the guard blocked the cross-tenant write
            }
        });

        // A still owns exactly its original three variants — nothing bled in from B.
        Tenant::run($accountA, fn () => $this->assertSame(3, ProductVariant::count()));
    }

    // --- Point 4: no allow-list leak (the un-scoped set still equals ALLOW_LIST) ---

    public function test_product_and_variant_are_not_global_and_allow_list_unchanged(): void
    {
        $this->assertFalse(GlobalModels::isGlobal(Product::class));
        $this->assertFalse(GlobalModels::isGlobal(ProductVariant::class));

        // The allow-list is exactly the documented set — nothing silently escaped.
        $this->assertSame([
            User::class,
            'App\\Models\\AiModel',
            'App\\Models\\AiOperation',
            'App\\Models\\Prompt',
            'App\\Models\\PlatformSetting',
            'App\\Models\\PlaygroundRun',
            'App\\Models\\StoryboardProject',
            'App\\Models\\StoryboardAsset',
            'App\\Models\\StoryboardFrame',
            'App\\Models\\StoryboardFrameVersion',
            'App\\Models\\StoryboardStepRun',
            // Shopify inbound-webhook inbox: rows are created PRE-BIND (no tenant is
            // known when a webhook arrives) — the SiteRouter exception class. Tenant
            // data flows only through the bound topic handler, never off this row.
            'App\\Models\\ShopifyWebhookReceipt',
            // Shopify install parked at the OAuth callback: PRE-BIND for the same reason
            // (an install starting on Shopify has no account yet). No tenant data on the
            // row; consumed exactly once by an authenticated account, then deleted.
            'App\\Models\\ShopifyPendingInstall',
        ], GlobalModels::ALLOW_LIST);
    }

    // --- Point 5: fail-closed (an unbound query returns the sentinel, not all rows) ---

    public function test_unbound_queries_fail_closed_for_products_and_variants(): void
    {
        $this->seedProductWithVariants();
        Tenant::clear();

        $this->assertFalse(Tenant::check());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductVariant::count());
        $this->assertNull(Product::query()->first());
        $this->assertNull(ProductVariant::query()->first());
    }

    // --- Point 1/3 combined: the wrong-account job hides A's product (NotFound) ---

    public function test_scan_job_for_account_b_cannot_touch_account_a_product(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();

        // A job declaring account B but pointing at A's site id: Site::findOrFail in
        // process() runs under B's bind, so A's site is invisible -> ModelNotFound,
        // never a cross-account write.
        $this->expectException(ModelNotFoundException::class);
        (new ScanProductJob($accountB->id, $siteA->id, self::URL_A))->handle();
    }

    // === HELPERS ===

    /**
     * @return array{0: Account, 1: Site, 2: Product}
     */
    private function seedProductWithVariants(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $product = Product::factory()->forSite($site)->create();
        ProductVariant::factory()->forProduct($product)->count(3)->create();

        return [$account, $site, $product];
    }

    private function fakeFetch(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/Scan/shopify_pdp.html'));

        $this->app->bind(PageSource::class, fn (): PageSource => new class($html) implements PageSource
        {
            public function __construct(private readonly string $html) {}

            public function fetch(string $url): FetchResult
            {
                return new FetchResult($this->html, $url, ScanConstants::FETCH_VIA_HTTP);
            }
        });
    }

    private function mockExtraction(): void
    {
        $json = json_encode([
            'product_name' => 'Merino Crew Sweater',
            'description' => 'A soft 100% merino wool crew-neck sweater.',
            'price' => 1299.00,
            'currency' => 'ILS',
            'product_type' => 'apparel',
            'main_image' => 'https://cdn.northstead.com/products/merino-crew-1200x.jpg',
            'images' => [],
            'variants' => [
                ['axis' => 'Color', 'value' => 'Forest', 'image' => null, 'available' => true],
                ['axis' => 'Size', 'value' => 'M', 'image' => null, 'available' => true],
            ],
            'physical_dimensions' => [],
            'selectors' => [
                'add_to_cart' => '#add-to-cart',
                'product_image' => '#hero-image',
                'title' => 'h1.product-title',
                'price' => '[data-product-price]',
                'description' => '.product-description',
                'variations' => '.product-form__input',
            ],
        ]);

        Http::fake([self::BASE.'/chat/completions' => Http::response([
            'id' => 'gen-scan-1',
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.0021],
            'choices' => [['message' => ['content' => $json]]],
        ], 200)]);
    }
}
