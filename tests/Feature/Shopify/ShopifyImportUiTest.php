<?php

namespace Tests\Feature\Shopify;

use App\Domain\Platform\PlatformSettings;
use App\Domain\Products\ConfirmImportedProducts;
use App\Domain\Products\PersistProduct;
use App\Domain\Shopify\Products\ShopifyProductMapper;
use App\Domain\Shopify\Products\StartShopifySync;
use App\Domain\Shopify\Products\StartSyncResult;
use App\Domain\Shopify\Products\SyncShopifyCatalogJob;
use App\Domain\Shopify\Products\SyncShopifyProductJob;
use App\Filament\Merchant\Pages\ShopifyProducts;
use App\Models\Account;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merchant's Shopify import screen.
 *
 * What is pinned: the page never persists a product itself (it opens a run and the queue
 * does the work), the import-all cap is a PLATFORM decision (config + super-admin
 * override — never merchant-settable), and "Confirm all N imported" is an EXPLICIT
 * merchant act that still runs the server-side ConfirmGate per product (nothing is
 * force-confirmed, nothing auto-approves).
 */
class ShopifyImportUiTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopify.client_id', 'test-client-id');
        config()->set('services.shopify.client_secret', 'test-client-secret');

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
    }

    public function test_the_page_renders_the_empty_state_for_a_store_with_no_shopify_connection(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $this->actingAs(User::factory()->forAccount($account)->create());
        Filament::setTenant($site);

        Tenant::run($account, function (): void {
            Livewire::test(ShopifyProducts::class)
                ->assertOk()
                ->assertSee(__('shopify.products.not_connected.heading'));
        });
    }

    public function test_import_all_opens_a_catalog_run_and_queues_the_walk(): void
    {
        Bus::fake();
        [$account, $site] = $this->boundShop();

        $this->fakeCatalogCount(2); // the cap probe: a small store, well inside the cap

        Tenant::run($account, function () use ($account, $site): void {
            Livewire::test(ShopifyProducts::class)
                ->callAction('importAll')
                ->assertHasNoActionErrors();

            $run = ShopifySyncRun::query()->firstOrFail();
            $this->assertSame(ShopifySyncRun::MODE_CATALOG, $run->mode);
            $this->assertSame((int) $account->id, (int) $run->account_id);
            $this->assertSame((int) $site->id, (int) $run->site_id);
            $this->assertNotNull($run->correlation_id);
        });

        Bus::assertDispatched(SyncShopifyCatalogJob::class, fn (SyncShopifyCatalogJob $job): bool => $job->accountId === (int) $account->id
            && $job->siteId === (int) $site->id
            && $job->cursor === null);
    }

    public function test_import_selected_queues_one_unique_job_per_picked_product(): void
    {
        Bus::fake();
        [$account, $site] = $this->boundShop();

        Tenant::run($account, function (): void {
            Livewire::test(ShopifyProducts::class)
                ->callAction('importSelected', ['gids' => [self::GID_A, self::GID_B]])
                ->assertHasNoActionErrors();

            $run = ShopifySyncRun::query()->firstOrFail();
            $this->assertSame(ShopifySyncRun::MODE_SELECTION, $run->mode);
            $this->assertSame([self::GID_A, self::GID_B], $run->requested_gids);
        });

        Bus::assertDispatchedTimes(SyncShopifyProductJob::class, 2);
    }

    public function test_the_import_cap_is_a_platform_decision_with_a_super_admin_override(): void
    {
        [$account] = $this->boundShop();

        $sync = app(StartShopifySync::class);

        // The config default.
        $this->assertSame((int) config('shopify.import.soft_cap'), $sync->softCap());
        $this->assertTrue($sync->exceedsCap((int) config('shopify.import.soft_cap') + 1));

        // A super-admin raises it (PlatformSettings — never a merchant-settable field).
        PlatformSetting::query()->create([
            PlatformSetting::COLUMN_KEY => PlatformSettings::SHOPIFY_IMPORT_CAP,
            'value' => '5000',
        ]);

        $this->assertSame(5000, app(StartShopifySync::class)->softCap());
        $this->assertFalse(app(StartShopifySync::class)->exceedsCap(4000));
    }

    /**
     * MUTATION-VERIFIED: delete the `if ($this->exceedsCap($size))` guard in
     * StartShopifySync::catalog() and this goes RED.
     *
     * The cap must be a GUARD, not a modal warning — a warning is a sentence the merchant
     * can click straight past, and the 40k-product store then lands in the bulk queue.
     * Asserting what softCap()/exceedsCap() RETURN proves nothing about that; this asserts
     * the BEHAVIOUR: over the cap, ZERO jobs are dispatched and NO run is even opened.
     */
    public function test_a_cap_exceeding_catalog_import_dispatches_nothing(): void
    {
        Bus::fake();
        [$account, $site] = $this->boundShop();

        config()->set('shopify.import.soft_cap', 10);
        $this->fakeCatalogCount(11); // one product over the cap

        $result = Tenant::run($account, fn (): StartSyncResult => app(StartShopifySync::class)->catalog($site));

        Bus::assertNotDispatched(SyncShopifyCatalogJob::class);
        Bus::assertNothingDispatched();

        $this->assertTrue($result->refused());
        $this->assertSame(StartSyncResult::REASON_OVER_CAP, $result->reason);
        $this->assertSame(11, $result->catalogSize);
        $this->assertSame(10, $result->cap);
        $this->assertNull($result->run, 'a refusal can carry no run');

        $this->assertSame(0, Tenant::run($account, fn (): int => ShopifySyncRun::count()), 'no run is opened');
    }

    /** The merchant SEES the refusal (a typed message), and the click does nothing else. */
    public function test_the_page_shows_the_cap_refusal_instead_of_starting_an_import(): void
    {
        Bus::fake();
        [$account] = $this->boundShop();

        config()->set('shopify.import.soft_cap', 10);
        $this->fakeCatalogCount(11);

        Tenant::run($account, function (): void {
            Livewire::test(ShopifyProducts::class)
                ->callAction('importAll')
                ->assertHasNoActionErrors()   // a refusal is a RESULT, never a 500
                ->assertNotified(__('shopify.products.notify.refused'));

            $this->assertSame(0, ShopifySyncRun::count());
        });

        Bus::assertNothingDispatched();
    }

    /** Shopify would not say how big the catalog is: we refuse rather than walk blind. */
    public function test_an_unmeasurable_catalog_is_refused_not_walked(): void
    {
        Bus::fake();
        [$account, $site] = $this->boundShop();

        $this->respondWith(fn () => Http::response(['errors' => [['message' => 'boom']]], 500));

        $result = Tenant::run($account, fn (): StartSyncResult => app(StartShopifySync::class)->catalog($site));

        $this->assertTrue($result->refused());
        $this->assertSame(StartSyncResult::REASON_SIZE_UNAVAILABLE, $result->reason);
        Bus::assertNothingDispatched();
        $this->assertSame(0, Tenant::run($account, fn (): int => ShopifySyncRun::count()));
    }

    public function test_confirm_all_imported_confirms_the_drafts_through_the_server_side_gate(): void
    {
        [$account, $site] = $this->boundShop();

        Tenant::run($account, function () use ($site): void {
            $this->importProduct($site, $this->productNode());
            $this->importProduct($site, $this->productNode(['id' => self::GID_B, 'handle' => 'linen', 'title' => 'Linen Shirt']));

            $this->assertSame(2, app(ConfirmImportedProducts::class)->pendingCount($site));

            Livewire::test(ShopifyProducts::class)
                ->callAction('confirmAll')
                ->assertHasNoActionErrors();

            $confirmed = Product::query()->where('status', Product::STATUS_CONFIRMED)->get();

            $this->assertCount(2, $confirmed);
            $this->assertTrue($confirmed->every(fn (Product $p): bool => $p->confirmed_at !== null));
            $this->assertSame(0, app(ConfirmImportedProducts::class)->pendingCount($site));
        });
    }

    /**
     * The no-auto-approve law: an IMPORT alone never confirms anything. Only the
     * merchant's explicit click does.
     */
    public function test_an_import_alone_never_confirms_a_product(): void
    {
        [$account, $site] = $this->boundShop();

        Tenant::run($account, function () use ($site): void {
            $this->importProduct($site, $this->productNode());

            $this->assertSame(0, Product::query()->where('status', Product::STATUS_CONFIRMED)->count());
            $this->assertSame(1, Product::query()->where('status', Product::STATUS_DRAFT)->count());
        });
    }

    /** A product the gate still blocks (no image at all) is SKIPPED, never forced. */
    public function test_a_blocked_product_is_skipped_by_the_bulk_confirm_never_forced(): void
    {
        [$account, $site] = $this->boundShop();

        Tenant::run($account, function () use ($site): void {
            // No featuredImage and no images: a try-on has nothing to render.
            $this->importProduct($site, $this->productNode([
                'featuredImage' => null,
                'images' => ['nodes' => []],
            ]));

            $result = app(ConfirmImportedProducts::class)->confirmAll($site);

            $this->assertSame(0, $result['confirmed']);
            $this->assertSame(1, $result['blocked']);
            $this->assertSame(Product::STATUS_DRAFT, Product::query()->firstOrFail()->status);
        });
    }

    public function test_the_page_counters_only_ever_count_this_shops_imported_products(): void
    {
        [$accountA, $siteA] = $this->boundShop();
        [$accountB, $siteB] = $this->connectedShop('other.myshopify.com');

        Tenant::run($accountA, fn () => $this->importProduct($siteA, $this->productNode()));
        Tenant::run($accountB, fn () => $this->importProduct($siteB, $this->productNode()));

        Tenant::run($accountA, function (): void {
            $counters = Livewire::test(ShopifyProducts::class)->instance()->counters();

            $this->assertSame(1, $counters['imported']);
            $this->assertSame(1, $counters['draft']);
            $this->assertSame(0, $counters['confirmed']);
            $this->assertSame(0, $counters['archived']);
        });
    }

    // === HELPERS ===

    /** A connected shop, bound as the merchant panel's tenant + auth user. */
    private function boundShop(): array
    {
        [$account, $site, $connection] = $this->connectedShop();

        $this->actingAs(User::factory()->forAccount($account)->create());
        Filament::setTenant($site->fresh());

        return [$account, $site->fresh(), $connection];
    }

    private function importProduct($site, array $node): Product
    {
        $mapper = app(ShopifyProductMapper::class);

        return app(PersistProduct::class)->persist(
            $site,
            $mapper->map($node, self::SHOP),
            $mapper->origin($node, self::SHOP)->toOrigin(),
        )->product;
    }
}
