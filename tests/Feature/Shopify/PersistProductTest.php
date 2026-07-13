<?php

namespace Tests\Feature\Shopify;

use App\Domain\Products\PersistProduct;
use App\Domain\Products\PersistResult;
use App\Domain\Products\ProductOrigin;
use App\Domain\Scan\Map\MappedProduct;
use App\Domain\Scan\ScanConstants;
use App\Domain\Shopify\Products\ShopifyProductMapper;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PersistProduct — the ONE writer both rails (PDP scan + Shopify) go through.
 *
 * The three laws, each with a MUTATION-verified test (delete the guard and the test goes
 * red — none of these can be satisfied by a constant + a comment):
 *
 *  1. REFRESH-CONFIRMED — a background refresh updates the DATA of a CONFIRMED product
 *     and NEVER its status. (Delete the `if (! $statusPreserved)` guard in
 *     PersistProduct::persist and test_a_refresh_of_a_confirmed_product_never_resets_its_status
 *     fails: the product is silently re-drafted and drops out of the widget.)
 *  2. UPSERT, NEVER REPLACE — a re-sync updates the SAME variant row, so a past
 *     generation's product_variant_id still resolves. (Replace the upsert with
 *     delete-and-recreate and test_a_resync_keeps_the_variant_ids_a_generation_points_at fails.)
 *  3. ARCHIVE, NEVER DELETE — a variant absent from the payload is deactivated, not
 *     deleted. (Swap archive() for delete() and
 *     test_a_variant_absent_from_the_payload_is_archived_not_deleted fails.)
 */
class PersistProductTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    // === LAW 1: REFRESH-CONFIRMED ===

    public function test_a_refresh_of_a_confirmed_product_never_resets_its_status(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));

        // The merchant confirms it — the ONLY path a product goes live.
        Tenant::run($account, function () use ($product): void {
            $product->fresh()->confirm();
        });

        $this->assertSame(Product::STATUS_CONFIRMED, $product->fresh()->status);

        // A webhook refresh lands with new data.
        $result = Tenant::run($account, fn () => $this->importResult($site, $this->productNode([
            'title' => 'Merino Crew Sweater (2026)',
        ])));

        $fresh = $product->fresh();

        $this->assertTrue($result->statusPreserved);
        $this->assertSame(Product::STATUS_CONFIRMED, $fresh->status, 'a refresh must never re-draft a confirmed product');
        $this->assertSame('Merino Crew Sweater (2026)', $fresh->name, 'but the DATA must be refreshed');
        $this->assertNotNull($fresh->last_synced_at);
    }

    public function test_a_refresh_of_a_draft_product_leaves_it_draft(): void
    {
        [$account, $site] = $this->connectedShop();

        Tenant::run($account, fn () => $this->import($site, $this->productNode()));
        $result = Tenant::run($account, fn () => $this->importResult($site, $this->productNode()));

        $this->assertFalse($result->created);
        $this->assertFalse($result->statusPreserved);
        $this->assertSame(Product::STATUS_DRAFT, $result->product->fresh()->status);
        $this->assertSame(1, Tenant::run($account, fn (): int => Product::count()), 'no duplicate row');
    }

    // === LAW 2: UPSERT, NEVER REPLACE ===

    public function test_a_resync_keeps_the_variant_ids_a_generation_points_at(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));

        $variantIds = Tenant::run($account, fn (): array => ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->orderBy('position')
            ->pluck('id', 'external_id')
            ->all());

        $this->assertCount(2, $variantIds);

        // A re-sync with the same variants (prices changed) must UPDATE the same rows.
        $node = $this->productNode();
        $node['variants']['nodes'][0]['price'] = '54.90';

        Tenant::run($account, fn () => $this->import($site, $node));

        $after = Tenant::run($account, fn (): array => ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->pluck('id', 'external_id')
            ->all());

        $this->assertSame($variantIds[self::VARIANT_A1], $after[self::VARIANT_A1], 'the variant id must survive a re-sync');
        $this->assertSame($variantIds[self::VARIANT_A2], $after[self::VARIANT_A2]);

        $updated = Tenant::run($account, fn (): ProductVariant => ProductVariant::query()
            ->where('external_id', self::VARIANT_A1)
            ->firstOrFail());
        $this->assertSame(5490, $updated->price_minor);
    }

    // === LAW 3: ARCHIVE, NEVER DELETE ===

    public function test_a_variant_absent_from_the_payload_is_archived_not_deleted(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));

        $doomed = Tenant::run($account, fn (): ProductVariant => ProductVariant::query()
            ->where('external_id', self::VARIANT_A2)
            ->firstOrFail());

        // A PAST, PAID try-on points at the variant that is about to disappear. The FK is
        // nullOnDelete: a hard delete would silently blank it and erase which variant the
        // shopper actually tried on.
        $generation = $this->generationOn($account, $site, $product, $doomed);

        // The store drops the M size.
        $node = $this->productNode();
        array_pop($node['variants']['nodes']);

        Tenant::run($account, fn () => $this->import($site, $node));

        $row = DB::table('product_variants')->where('id', $doomed->getKey())->first();

        $this->assertNotNull($row, 'the variant row must survive — generations FK it');
        $this->assertSame(0, (int) $row->is_active);
        $this->assertNotNull($row->archived_at);

        // The paid history still knows exactly which variant was tried on.
        $this->assertSame(
            (int) $doomed->getKey(),
            (int) DB::table('generations')->where('id', $generation->getKey())->value('product_variant_id'),
        );

        // And the archived variant is no longer offered for a NEW generation.
        $active = Tenant::run($account, fn (): int => $product->fresh()->activeVariants()->count());
        $this->assertSame(1, $active);
    }

    public function test_a_variant_that_reappears_is_reactivated_in_place(): void
    {
        [$account, $site] = $this->connectedShop();

        Tenant::run($account, fn () => $this->import($site, $this->productNode()));

        $archivedId = Tenant::run($account, fn (): int => (int) ProductVariant::query()
            ->where('external_id', self::VARIANT_A2)
            ->value('id'));

        $node = $this->productNode();
        array_pop($node['variants']['nodes']);
        Tenant::run($account, fn () => $this->import($site, $node));

        // The merchant restores the size.
        Tenant::run($account, fn () => $this->import($site, $this->productNode()));

        $restored = Tenant::run($account, fn (): ProductVariant => ProductVariant::query()
            ->where('external_id', self::VARIANT_A2)
            ->firstOrFail());

        $this->assertSame($archivedId, (int) $restored->getKey(), 'the SAME row is reactivated, not a new one');
        $this->assertTrue($restored->is_active);
        $this->assertNull($restored->archived_at);
    }

    // === IDENTITY ===

    public function test_a_scanned_product_stores_null_external_id_never_an_empty_string(): void
    {
        [$account, $site] = $this->connectedShop();

        $mapped = new MappedProduct(
            fields: ['name' => ['value' => 'Scanned', 'confidence' => 0.9, 'source' => ScanConstants::SOURCE_JSONLD]],
            variantAxes: [], variantRows: [], dimensions: [], detectedSelectors: [],
            confidence: 0.9, raw: [], fetchedVia: 'http', warnings: [],
        );

        Tenant::run($account, fn () => app(PersistProduct::class)
            ->persist($site, $mapped, ProductOrigin::scan('https://shop.test/products/x')));

        $external = DB::table('products')->where('site_id', $site->getKey())->value('external_id');

        // '' would COLLIDE in the (site_id, external_id) unique index; NULL is excluded from it.
        $this->assertNull($external);
        $this->assertSame(Product::SOURCE_SCAN, DB::table('products')->where('site_id', $site->getKey())->value('source'));
    }

    public function test_importing_a_previously_scanned_url_adopts_the_row_instead_of_duplicating_it(): void
    {
        [$account, $site] = $this->connectedShop();

        // The merchant scanned this PDP before connecting Shopify, and confirmed it.
        $scanned = Product::factory()->forSite($site)->confirmed()->create([
            'source_url' => 'https://northstead.com/products/merino-crew',
            'source_url_hash' => sha1('https://northstead.com/products/merino-crew'),
            'source' => Product::SOURCE_SCAN,
        ]);

        Tenant::run($account, fn () => $this->import($site, $this->productNode()));

        $this->assertSame(1, Tenant::run($account, fn (): int => Product::count()), 'the scanned row is adopted, not duplicated');

        $fresh = $scanned->fresh();
        $this->assertSame(Product::SOURCE_SHOPIFY, $fresh->source);
        $this->assertSame(self::GID_A, $fresh->external_id);
        $this->assertSame(Product::STATUS_CONFIRMED, $fresh->status, 'the merchant confirm survives the adoption');
    }

    public function test_a_confirmed_scan_is_never_overwritten_by_a_rescan_of_the_same_url(): void
    {
        [$account, $site] = $this->connectedShop();

        $confirmed = Product::factory()->forSite($site)->confirmed()->create([
            'name' => 'Merchant corrected name',
            'source_url' => 'https://shop.test/products/x',
            'source_url_hash' => sha1('https://shop.test/products/x'),
        ]);

        $mapped = new MappedProduct(
            fields: ['name' => ['value' => 'Re-scanned name', 'confidence' => 0.9, 'source' => ScanConstants::SOURCE_DOM]],
            variantAxes: [], variantRows: [], dimensions: [], detectedSelectors: [],
            confidence: 0.9, raw: [], fetchedVia: 'http', warnings: [],
        );

        Tenant::run($account, fn () => app(PersistProduct::class)
            ->persist($site, $mapped, ProductOrigin::scan('https://shop.test/products/x')));

        // The re-scan creates a NEW draft; the confirmed row keeps the merchant's edits.
        $this->assertSame('Merchant corrected name', $confirmed->fresh()->name);
        $this->assertSame(Product::STATUS_CONFIRMED, $confirmed->fresh()->status);
    }

    // === TENANCY ===

    public function test_a_persist_stamps_the_bound_account_and_never_leaks_across_accounts(): void
    {
        [$accountA, $siteA] = $this->connectedShop();
        [$accountB, $siteB] = $this->connectedShop('other-store.myshopify.com');

        Tenant::run($accountA, fn () => $this->import($siteA, $this->productNode()));
        Tenant::run($accountB, fn () => $this->import($siteB, $this->productNode()));

        // The same Shopify GID in two stores => two rows, each owned by its account.
        $this->assertSame(1, Tenant::run($accountA, fn (): int => Product::count()));
        $this->assertSame(1, Tenant::run($accountB, fn (): int => Product::count()));

        $a = Tenant::run($accountA, fn (): Product => Product::query()->firstOrFail());
        $b = Tenant::run($accountB, fn (): Product => Product::query()->firstOrFail());

        $this->assertSame((int) $accountA->id, (int) $a->account_id);
        $this->assertSame((int) $accountB->id, (int) $b->account_id);
        $this->assertNotSame($a->getKey(), $b->getKey());

        // And the worker leaks no tenant.
        $this->assertFalse(Tenant::check());
    }

    // === HELPERS ===

    /** A succeeded, paid try-on that references this exact variant. */
    private function generationOn($account, $site, Product $product, ProductVariant $variant): Generation
    {
        $endUser = EndUser::factory()->forSite($site)->create();

        return Generation::factory()
            ->forContext($endUser, $product, $variant, 'crq_'.$variant->getKey())
            ->create();
    }

    private function import($site, array $node): Product
    {
        return $this->importResult($site, $node)->product;
    }

    private function importResult($site, array $node): PersistResult
    {
        $mapper = app(ShopifyProductMapper::class);

        return app(PersistProduct::class)->persist(
            $site,
            $mapper->map($node, self::SHOP),
            $mapper->origin($node, self::SHOP)->toOrigin(),
        );
    }
}
