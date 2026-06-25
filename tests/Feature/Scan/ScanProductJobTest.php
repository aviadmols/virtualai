<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\ScanConstants;
use App\Domain\Scan\ScanProductJob;
use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The full scan vertical, network + the OpenRouter call MOCKED. Proves: a server-
 * rendered PDP scans to a draft Product with variants + per-field confidence +
 * verified selectors; a scan NEVER auto-approves; the idempotency key collapses a
 * double dispatch; a fetch failure persists a FAILED product with a merchant reason.
 */
class ScanProductJobTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://openrouter.ai/api/v1';
    private const URL = 'https://shop.northstead.com/products/merino-crew';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::BASE);
        config()->set('services.openrouter.timeout', 30);
    }

    /** Bind a page source that returns the fixture HTML — no network. */
    private function fakeFetch(string $fixture = 'shopify_pdp.html', string $via = ScanConstants::FETCH_VIA_HTTP): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/Scan/'.$fixture));

        $this->app->bind(PageSource::class, fn (): PageSource => new class($html, $via) implements PageSource {
            public function __construct(private readonly string $html, private readonly string $via) {}

            public function fetch(string $url): FetchResult
            {
                return new FetchResult($this->html, $url, $this->via);
            }
        });
    }

    /** Bind a page source that throws a typed, merchant-facing failure. */
    private function fakeFetchFailure(string $reason): void
    {
        $this->app->bind(PageSource::class, fn (): PageSource => new class($reason) implements PageSource {
            public function __construct(private readonly string $reason) {}

            public function fetch(string $url): FetchResult
            {
                throw FetchException::failed($this->reason);
            }
        });
    }

    /** The mocked extraction JSON the model returns (strict, schema-valid). */
    private function mockExtraction(): void
    {
        $json = json_encode([
            'product_name' => 'Merino Crew Sweater',
            'description' => 'A soft 100% merino wool crew-neck sweater.',
            'price' => 1299.00,
            'currency' => 'ILS',
            'product_type' => 'apparel',
            'main_image' => 'https://cdn.northstead.com/products/merino-crew-1200x.jpg',
            'images' => ['https://cdn.northstead.com/products/merino-crew-back-1200x.jpg'],
            'variants' => [
                ['axis' => 'Color', 'value' => 'Forest', 'image' => null, 'available' => true],
                ['axis' => 'Color', 'value' => 'Charcoal', 'image' => null, 'available' => true],
                ['axis' => 'Size', 'value' => 'S', 'image' => null, 'available' => true],
                ['axis' => 'Size', 'value' => 'M', 'image' => null, 'available' => true],
                ['axis' => 'Size', 'value' => 'L', 'image' => null, 'available' => true],
            ],
            'physical_dimensions' => ['M' => ['chest' => '52cm']],
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

    private function dispatch(Account $account, Site $site, string $url = self::URL): void
    {
        (new ScanProductJob($account->id, $site->id, $url))->handle();
    }

    public function test_happy_path_persists_draft_product_with_variants_and_selectors(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $this->fakeFetch();
        $this->mockExtraction();

        $this->dispatch($account, $site);

        $product = Tenant::run($account, fn () => Product::where('site_id', $site->id)->first());

        $this->assertNotNull($product);
        // NEVER auto-approves.
        $this->assertSame(Product::STATUS_DRAFT, $product->status);
        $this->assertNull($product->confirmed_at);

        // Mapped fields.
        $this->assertSame('Merino Crew Sweater', $product->name);
        $this->assertSame('apparel', $product->product_type);

        // Locale-aware price: 1299.00 ILS = 129900 minor units, from JSON-LD offer.
        $this->assertSame(129_900, $product->price_minor);
        $this->assertSame('ILS', $product->currency);

        // Lazy hero resolved to the real high-res image, not the placeholder GIF.
        $this->assertStringContainsString('merino-crew-1200x.jpg', $product->main_image_url);
        $this->assertStringNotContainsString('data:image', (string) $product->main_image_url);

        // Per-field confidence present, with source provenance.
        $this->assertArrayHasKey('name', $product->field_confidence);
        $this->assertSame(ScanConstants::SOURCE_JSONLD, $product->field_confidence['name']['source']);

        // Two variant axes (color swatch + size dropdown) — the classic miss avoided.
        $axes = collect($product->scan_raw['model_json']['variants'])->pluck('axis')->unique();
        $this->assertEqualsCanonicalizing(['Color', 'Size'], $axes->values()->all());

        // Variant rows persisted, tenant-stamped.
        $variants = Tenant::run($account, fn () => $product->variants()->get());
        $this->assertCount(5, $variants);
        $this->assertTrue($variants->every(fn ($v) => $v->account_id === $account->id));

        // Six selectors, each resolving to exactly one element in the fixture.
        $selectors = $product->detected_selectors;
        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $this->assertArrayHasKey($role, $selectors);
        }
        $this->assertSame(1, $selectors[ScanConstants::ROLE_ADD_TO_CART]['matched_count']);
        $this->assertFalse($selectors[ScanConstants::ROLE_ADD_TO_CART]['needs_review']);
    }

    public function test_scan_never_auto_approves_and_confirm_is_required(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $this->fakeFetch();
        $this->mockExtraction();

        $this->dispatch($account, $site);

        Tenant::run($account, function () use ($site) {
            $product = Product::where('site_id', $site->id)->firstOrFail();
            $this->assertSame(Product::STATUS_DRAFT, $product->status);

            // Only confirm() makes it live.
            $product->confirm(['name' => 'Merino Crew (confirmed)']);
            $this->assertSame(Product::STATUS_CONFIRMED, $product->fresh()->status);
            $this->assertNotNull($product->fresh()->confirmed_at);
            $this->assertSame('Merino Crew (confirmed)', $product->fresh()->name);
        });
    }

    public function test_idempotency_key_collapses_double_dispatch(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $this->fakeFetch();
        $this->mockExtraction();

        // Two scans of the SAME (account, site, url) — the unique key is stable.
        $key = ScanProductJob::scanKey($account->id, $site->id, self::URL);
        $this->assertSame('scan:'.$account->id.':'.$site->id.':'.sha1(self::URL), $key);

        $this->dispatch($account, $site);
        $this->dispatch($account, $site); // re-scan updates the draft, never duplicates

        $count = Tenant::run($account, fn () => Product::where('site_id', $site->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_unique_id_matches_the_locked_scan_key(): void
    {
        $job = new ScanProductJob(7, 42, self::URL);

        $this->assertSame('scan:7:42:'.sha1(self::URL), $job->uniqueId());
    }

    public function test_fetch_failure_persists_failed_product_with_merchant_reason(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $this->fakeFetchFailure(ScanConstants::FAIL_BOT_BLOCKED);

        $this->dispatch($account, $site);

        $product = Tenant::run($account, fn () => Product::where('site_id', $site->id)->first());

        $this->assertNotNull($product);
        $this->assertSame(Product::STATUS_FAILED, $product->status);
        $this->assertSame(ScanConstants::FAIL_BOT_BLOCKED, $product->warnings['reason']);
        $this->assertTrue($product->warnings['suggest_manual']);
        $this->assertStringContainsString('manually', $product->warnings['message']);
    }

    public function test_headless_screenshot_path_still_extracts(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        // Even when fetched via headless, the extraction maps to a draft product.
        $this->fakeFetch('shopify_pdp.html', ScanConstants::FETCH_VIA_HEADLESS);
        $this->mockExtraction();

        $this->dispatch($account, $site);

        $product = Tenant::run($account, fn () => Product::where('site_id', $site->id)->first());

        $this->assertSame(Product::STATUS_DRAFT, $product->status);
        $this->assertSame(ScanConstants::FETCH_VIA_HEADLESS, $product->fetched_via);
        $this->assertSame('Merino Crew Sweater', $product->name);
    }
}
