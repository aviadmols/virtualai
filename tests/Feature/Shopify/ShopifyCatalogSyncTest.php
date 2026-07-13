<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Api\ShopifyApiException;
use App\Domain\Shopify\Products\ShopifyProductImporter;
use App\Domain\Shopify\Products\StartShopifySync;
use App\Domain\Shopify\Products\StartSyncResult;
use App\Domain\Shopify\Products\SyncShopifyCatalogJob;
use App\Domain\Shopify\Products\SyncShopifyProductJob;
use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The catalog walk: paginated, cursor-resumable, throttle-safe, lifecycle-correct.
 *
 * Bus is faked throughout so the SELF-REDISPATCH is asserted explicitly (and each page is
 * driven by hand) instead of running inline on the `sync` queue — the pages must be
 * independent, resumable units, and a test that lets them cascade would prove nothing
 * about resumability.
 *
 * MUTATION-VERIFIED GUARDS (each one is a merchant's catalog, or their import, on the line):
 *  - test_a_selection_run_never_archives_the_products_it_did_not_import — delete the
 *    `if ($run->mode !== ShopifySyncRun::MODE_CATALOG)` line in
 *    ShopifyProductImporter::archiveStale and it goes red: a two-product import would
 *    archive the whole rest of the store (every untouched product's last_synced_at
 *    predates the run).
 *  - test_a_truncated_walk_archives_nothing — replace the completeness guard in
 *    SyncShopifyCatalogJob::process() with an unconditional archiveStale() and it goes red:
 *    a walk that ran out of page budget would archive every LIVE product beyond the budget.
 *  - test_a_throttled_page_never_burns_a_queue_try — put `$this->release()` back into
 *    park() and it goes red: five throttled pages would FAIL a run that is merely waiting.
 *  - test_both_sync_jobs_are_unique_jobs_keyed_by_their_deterministic_id — drop
 *    `implements ShouldBeUnique` from either job and it goes red (asserting the uniqueId
 *    STRING alone does not, which is why the interface itself is asserted).
 */
class ShopifyCatalogSyncTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    // The pinned unique-lock windows (a page lock outlives a page; a product lock is short).
    private const CATALOG_UNIQUE_FOR = 3600;

    private const PRODUCT_UNIQUE_FOR = 900;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();  // the throttle backoff must never really sleep in the suite
        Bus::fake();    // pages are driven by hand; the redispatch is asserted, not run
    }

    public function test_a_catalog_walk_imports_every_page_and_completes_the_run(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeCatalog([
            [[$this->productNode()], true, 'cursor-1'],
            [[$this->productNode(['id' => self::GID_B, 'handle' => 'linen-shirt', 'title' => 'Linen Shirt'])], false, 'cursor-2'],
        ]);

        $run = $this->startCatalog($account, $site);

        // Page 1 persists its resume point and hands the NEXT cursor to a fresh job.
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null))->handle();

        $this->assertSame('cursor-1', $run->fresh()->cursor);
        $this->assertSame(ShopifySyncRun::STATUS_RUNNING, $run->fresh()->status);
        Bus::assertDispatched(SyncShopifyCatalogJob::class, fn (SyncShopifyCatalogJob $job): bool => $job->cursor === 'cursor-1');

        // Page 2 (the last) completes the run.
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1'))->handle();

        $fresh = $run->fresh();
        $this->assertSame(ShopifySyncRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(2, $fresh->total_seen);
        $this->assertSame(2, $fresh->imported);
        $this->assertSame(2, $fresh->pages);
        $this->assertNotNull($fresh->finished_at);
        $this->assertFalse($fresh->isTruncated(), 'a walk that reached the last page is COMPLETE');

        $products = Tenant::run($account, fn () => Product::query()->orderBy('id')->get());
        $this->assertCount(2, $products);
        $this->assertSame([self::GID_A, self::GID_B], $products->pluck('external_id')->all());
        $this->assertTrue($products->every(fn (Product $p): bool => $p->status === Product::STATUS_DRAFT), 'an import NEVER auto-approves');
        $this->assertTrue($products->every(fn (Product $p): bool => $p->source === Product::SOURCE_SHOPIFY));

        $this->assertFalse(Tenant::check()); // no tenant leaked into the next job
    }

    public function test_a_throttled_page_parks_the_cursor_and_loses_nothing(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeThrottleAfterFirstPage();

        $run = $this->startCatalog($account, $site);

        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null))->handle();

        $this->assertSame('cursor-1', $run->fresh()->cursor, 'the resume point is persisted BEFORE the next page');

        // Shopify throttles page 2 past the client's retry budget: the run stays RUNNING
        // (parked), nothing is imported twice, nothing is marked failed, the cursor holds.
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1'))->handle();

        $fresh = $run->fresh();
        $this->assertSame(ShopifySyncRun::STATUS_RUNNING, $fresh->status);
        $this->assertSame('cursor-1', $fresh->cursor);
        $this->assertSame(1, $fresh->imported);
        $this->assertNull($fresh->last_error);
        $this->assertSame(1, Tenant::run($account, fn (): int => Product::count()));

        // The parked page comes back as a FRESH delayed job for the SAME cursor.
        Bus::assertDispatched(
            SyncShopifyCatalogJob::class,
            fn (SyncShopifyCatalogJob $job): bool => $job->cursor === 'cursor-1' && $job->parks === 1 && $job->delay === 30,
        );
    }

    /**
     * MUTATION-VERIFIED: put `$this->release(self::PARK_SECONDS)` back into park() and this
     * goes RED. A park is NOT a failure: release() burns one of $tries on every reservation,
     * so a store that throttles us five times in a row would FAIL the run — and FAILED is
     * terminal, it never re-opens. The merchant's whole import would die of rate limiting.
     */
    public function test_a_throttled_page_never_burns_a_queue_try(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeThrottleAfterFirstPage();

        $run = $this->startCatalog($account, $site);

        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null))->handle();

        // Five parks in a row — one more than $tries would survive if a park consumed one.
        for ($park = 0; $park < 5; $park++) {
            $job = (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1', $park))
                ->withFakeQueueInteractions();

            $job->handle();

            $job->assertNotReleased(); // a park must never spend a try
            $job->assertNotFailed();

            $this->assertSame(ShopifySyncRun::STATUS_RUNNING, $run->fresh()->status, "park #{$park} must not fail the run");
        }

        $fresh = $run->fresh();
        $this->assertSame('cursor-1', $fresh->cursor, 'the resume point survives every park');
        $this->assertNull($fresh->last_error);
        $this->assertSame(1, $fresh->imported, 'nothing is re-imported by a park');

        // Each park queued the NEXT attempt of the same page (a fresh job, attempt 1 again).
        Bus::assertDispatched(
            SyncShopifyCatalogJob::class,
            fn (SyncShopifyCatalogJob $job): bool => $job->cursor === 'cursor-1' && $job->parks === 5,
        );
    }

    /** The park budget is finite: a store that throttles forever ends as a REPORTED failure. */
    public function test_a_store_that_throttles_past_the_park_budget_fails_the_run_once(): void
    {
        [$account, $site] = $this->connectedShop();

        config()->set('shopify.sync.max_parks', 2);

        $this->fakeThrottleAfterFirstPage();

        $run = $this->startCatalog($account, $site);

        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null))->handle();

        // parks = the budget -> no further re-dispatch; the run stops, loudly.
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1', 2))->handle();

        $fresh = $run->fresh();
        $this->assertSame(ShopifySyncRun::STATUS_FAILED, $fresh->status);
        $this->assertSame('shopify_throttled', $fresh->last_error);

        Bus::assertNotDispatched(
            SyncShopifyCatalogJob::class,
            fn (SyncShopifyCatalogJob $job): bool => $job->parks === 3,
        );
    }

    /**
     * MUTATION-VERIFIED: put `$this->release(self::PARK_SECONDS)` back into
     * SyncShopifyProductJob::park() and this goes RED.
     *
     * The same law as the catalog walk, one product wide: a park is NOT a failure. release()
     * spends one of $tries on every reservation, so a store that throttles this product five
     * times in a row would FAIL it for good — it would silently drop out of the merchant's
     * import for no reason but rate limiting. The job COMPLETES instead and queues a FRESH
     * attempt for the SAME gid (attempt 1 again), carrying the park count so the stall stays
     * bounded.
     */
    public function test_a_throttled_product_never_burns_a_queue_try(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeThrottledProduct();

        $run = ShopifySyncRun::factory()->forSite($site)->create([
            'mode' => ShopifySyncRun::MODE_SELECTION,
            'requested_gids' => [self::GID_A],
        ]);

        // Five parks in a row — one more than $tries would survive if a park consumed one.
        for ($park = 0; $park < 5; $park++) {
            $job = (new SyncShopifyProductJob((int) $account->id, (int) $site->id, self::GID_A, (int) $run->id, $park))
                ->withFakeQueueInteractions();

            $job->handle();

            $job->assertNotReleased(); // a park must never spend a try
            $job->assertNotFailed();
        }

        $fresh = $run->fresh();
        $this->assertSame(ShopifySyncRun::STATUS_RUNNING, $fresh->status, 'a throttled product never fails the run');
        $this->assertSame(0, (int) $fresh->failed);
        $this->assertNull($fresh->last_error);

        // Each park queued the NEXT attempt of the SAME product (a fresh job, attempt 1 again).
        Bus::assertDispatched(
            SyncShopifyProductJob::class,
            fn (SyncShopifyProductJob $job): bool => $job->gid === self::GID_A && $job->parks === 5 && $job->delay === 30,
        );
    }

    /** The product park budget is finite too: past it, a throttle is a real, reported error. */
    public function test_a_product_throttled_past_the_park_budget_stops_parking(): void
    {
        [$account, $site] = $this->connectedShop();

        config()->set('shopify.sync.max_parks', 2);

        $this->fakeThrottledProduct();

        $run = ShopifySyncRun::factory()->forSite($site)->create([
            'mode' => ShopifySyncRun::MODE_SELECTION,
            'requested_gids' => [self::GID_A],
        ]);

        $job = new SyncShopifyProductJob((int) $account->id, (int) $site->id, self::GID_A, (int) $run->id, 2);

        $this->expectException(ShopifyApiException::class);

        try {
            $job->handle();
        } finally {
            Bus::assertNotDispatched(
                SyncShopifyProductJob::class,
                fn (SyncShopifyProductJob $queued): bool => $queued->parks === 3,
            );
        }
    }

    public function test_a_parked_run_resumes_from_its_cursor_and_completes(): void
    {
        [$account, $site] = $this->connectedShop();

        $run = ShopifySyncRun::factory()->forSite($site)->running()->create(['cursor' => 'cursor-1']);

        $this->fakeCatalog([
            [[$this->productNode()], true, 'cursor-1'],
            [[$this->productNode(['id' => self::GID_B, 'handle' => 'linen-shirt'])], false, 'cursor-2'],
        ]);

        // Resuming from the parked cursor imports ONLY the remaining page — page 1 is
        // never re-walked (that is what makes the throttle recovery free).
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1'))->handle();

        $this->assertSame(ShopifySyncRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, Tenant::run($account, fn (): int => Product::count()));
        $this->assertSame(self::GID_B, Tenant::run($account, fn (): ?string => Product::query()->value('external_id')));
    }

    public function test_a_product_gone_from_the_catalog_is_archived_never_deleted(): void
    {
        [$account, $site] = $this->connectedShop();

        // Two products imported by an earlier walk.
        $this->fakeCatalog([[[$this->productNode(), $this->productNode(['id' => self::GID_B, 'handle' => 'linen'])], false, null]]);
        $first = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $first->id, null))->handle();

        $this->assertSame(2, Tenant::run($account, fn (): int => Product::count()));

        // The merchant deletes one product; the next walk only returns the other.
        $this->travel(2)->seconds();
        $this->fakeCatalog([[[$this->productNode()], false, null]]);
        $second = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $second->id, null))->handle();

        $this->assertSame(2, Tenant::run($account, fn (): int => Product::count()), 'never deleted');

        $gone = Tenant::run($account, fn (): Product => Product::query()->where('external_id', self::GID_B)->firstOrFail());
        $this->assertFalse($gone->is_active);
        $this->assertNotNull($gone->archived_at);

        $kept = Tenant::run($account, fn (): Product => Product::query()->where('external_id', self::GID_A)->firstOrFail());
        $this->assertTrue($kept->is_active);

        $this->assertSame(1, $second->fresh()->archived);
    }

    /**
     * MUTATION-VERIFIED: delete the MODE_CATALOG guard in archiveStale() and this fails.
     * A selection import says NOTHING about the products it did not include — archiving
     * "everything I did not see" would wipe the merchant's whole catalog on a 2-product
     * import.
     */
    public function test_a_selection_run_never_archives_the_products_it_did_not_import(): void
    {
        [$account, $site] = $this->connectedShop();

        // An established catalog (imported before the new run started).
        $this->fakeCatalog([[[$this->productNode(), $this->productNode(['id' => self::GID_B, 'handle' => 'linen'])], false, null]]);
        $walk = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $walk->id, null))->handle();

        $this->travel(2)->seconds();

        // The merchant now re-imports ONE product by hand.
        $selection = ShopifySyncRun::factory()->forSite($site)->running()->selection([self::GID_A])->create();

        Tenant::run($account, function () use ($site, $selection): void {
            $archived = app(ShopifyProductImporter::class)->archiveStale($site, $selection);

            $this->assertSame(0, $archived, 'a selection run must archive NOTHING');
        });

        // Both products are still active — the untouched one was NOT archived.
        $active = Tenant::run($account, fn (): int => Product::query()->where('is_active', true)->count());
        $this->assertSame(2, $active);
    }

    // === THE COMPLETENESS LAW (blocker 1) ===

    /**
     * MUTATION-VERIFIED: drop the `if ($page->hasNextPage)` completeness guard in
     * SyncShopifyCatalogJob::process() (sweep unconditionally) and this goes RED.
     *
     * archiveStale's premise — "anything Shopify did not return is gone from the store" —
     * is FALSE for a walk cut short by the page budget. Sweeping there archives every LIVE
     * product beyond the budget: they drop out of the widget and the merchant loses their
     * catalog to a config value.
     */
    public function test_a_truncated_walk_archives_nothing_and_says_so(): void
    {
        [$account, $site] = $this->connectedShop();

        config()->set('shopify.sync.max_pages', 1); // the budget runs out after page 1

        // A LIVE product the earlier (complete) walk imported, and that page 2 still holds.
        $this->fakeCatalog([[[$this->productNode(['id' => self::GID_B, 'handle' => 'linen'])], false, null]]);
        $seed = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $seed->id, null))->handle();

        $this->travel(2)->seconds();

        // The new walk sees GID_A on page 1 and STOPS: its budget is spent, page 2 (which
        // still carries the LIVE GID_B) is never read.
        $this->fakeCatalog([
            [[$this->productNode()], true, 'cursor-1'],
            [[$this->productNode(['id' => self::GID_B, 'handle' => 'linen'])], false, 'cursor-2'],
        ]);

        $run = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null))->handle();

        $fresh = $run->fresh();

        $this->assertSame(ShopifySyncRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(0, $fresh->archived, 'a truncated walk may archive NOTHING');

        // The live product the walk never reached is untouched — still active, still in the widget.
        $unreached = Tenant::run($account, fn (): Product => Product::query()->where('external_id', self::GID_B)->firstOrFail());
        $this->assertTrue($unreached->is_active);
        $this->assertNull($unreached->archived_at);

        // ...and the run says so: on the row, and on the timeline.
        $this->assertTrue($fresh->isTruncated());
        $this->assertSame(ShopifySyncRun::TRUNCATION_MAX_PAGES, $fresh->truncated_reason);

        $kinds = Tenant::run($account, fn (): array => ActivityEvent::query()->pluck('kind')->all());
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_SYNC_TRUNCATED, $kinds);

        // No further page was queued — the budget is a hard stop, not a pause.
        Bus::assertNotDispatched(
            SyncShopifyCatalogJob::class,
            fn (SyncShopifyCatalogJob $job): bool => $job->cursor === 'cursor-1',
        );
    }

    /** A COMPLETE walk over the same store still sweeps — the guard is about completeness, not caution. */
    public function test_a_complete_walk_still_archives_what_the_store_no_longer_has(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeCatalog([[[$this->productNode(), $this->productNode(['id' => self::GID_B, 'handle' => 'linen'])], false, null]]);
        $first = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $first->id, null))->handle();

        $this->travel(2)->seconds();

        // Two pages, both read (the budget is not hit): GID_B is genuinely gone.
        $this->fakeCatalog([
            [[$this->productNode()], true, 'cursor-1'],
            [[], false, 'cursor-2'],
        ]);

        $run = $this->startCatalog($account, $site);
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null))->handle();
        (new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1'))->handle();

        $fresh = $run->fresh();

        $this->assertFalse($fresh->isTruncated());
        $this->assertSame(1, $fresh->archived, 'a COMPLETE walk archives what the store no longer returns');
        $this->assertFalse(
            Tenant::run($account, fn (): Product => Product::query()->where('external_id', self::GID_B)->firstOrFail())->is_active,
        );
    }

    // === IDEMPOTENCY (blocker 3) ===

    /**
     * MUTATION-VERIFIED: remove `implements ShouldBeUnique` from EITHER sync job and this
     * goes RED. Asserting the uniqueId() STRING is not enough — the string survives the
     * interface being dropped, and the queue would then happily run two copies of the same
     * page (or the same product) side by side.
     */
    public function test_both_sync_jobs_are_unique_jobs_keyed_by_their_deterministic_id(): void
    {
        [$account, $site] = $this->connectedShop();

        $run = ShopifySyncRun::factory()->forSite($site)->create();

        $catalog = new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, null);

        $this->assertInstanceOf(ShouldBeUnique::class, $catalog);
        $this->assertSame(self::CATALOG_UNIQUE_FOR, $catalog->uniqueFor);
        $this->assertSame(
            'shopify_catalog:'.$account->id.':'.$site->id.':'.$run->id.':start:0',
            $catalog->uniqueId(),
        );

        // A park is a NEW unit of work: it must NOT collide with the lock its predecessor
        // still holds while it dispatches the retry from inside handle().
        $parked = new SyncShopifyCatalogJob((int) $account->id, (int) $site->id, (int) $run->id, 'cursor-1', 1);
        $this->assertNotSame($catalog->uniqueId(), $parked->uniqueId());

        $product = new SyncShopifyProductJob((int) $account->id, (int) $site->id, self::GID_A, (int) $run->id);

        $this->assertInstanceOf(ShouldBeUnique::class, $product);
        $this->assertSame(self::PRODUCT_UNIQUE_FOR, $product->uniqueFor);
        $this->assertSame(
            'shopify_product:'.$account->id.':'.$site->id.':'.self::GID_A.':0',
            $product->uniqueId(),
        );

        // A double-clicked import / a replayed webhook of the SAME product still collapses…
        $twin = new SyncShopifyProductJob((int) $account->id, (int) $site->id, self::GID_A, (int) $run->id);
        $this->assertSame($product->uniqueId(), $twin->uniqueId());

        // …while a PARKED retry is a new unit of work: it must not collide with the lock its
        // predecessor still holds while it dispatches from inside handle() (same law as above).
        $parkedProduct = new SyncShopifyProductJob((int) $account->id, (int) $site->id, self::GID_A, (int) $run->id, 1);
        $this->assertNotSame($product->uniqueId(), $parkedProduct->uniqueId());
    }

    public function test_a_selection_import_dispatches_one_unique_job_per_gid(): void
    {
        [$account, $site] = $this->connectedShop();

        $run = Tenant::run($account, fn (): ShopifySyncRun => app(StartShopifySync::class)
            ->selection($site, [self::GID_A, self::GID_B, self::GID_A])->run); // a duplicated pick

        $this->assertSame(ShopifySyncRun::MODE_SELECTION, $run->mode);
        $this->assertSame([self::GID_A, self::GID_B], $run->requested_gids, 'duplicates collapse');

        Bus::assertDispatchedTimes(SyncShopifyProductJob::class, 2);
    }

    /**
     * MUTATION-VERIFIED: put the silent `array_slice(..., 0, $max)` back inside normaliseGids()
     * (so selection() never learns what it dropped) and this goes RED.
     *
     * A bound that quietly eats picks tells the merchant "import started" about products nothing
     * ever touched. The picks past selection_max are NOT imported, so they are REPORTED: the run
     * is marked truncated and the dropped count rides back on the typed result.
     */
    public function test_a_selection_past_the_bound_is_reported_not_silently_sliced(): void
    {
        [$account, $site] = $this->connectedShop();

        config()->set('shopify.import.selection_max', 2);

        $picks = [self::GID_A, self::GID_B, 'gid://shopify/Product/999'];

        $result = Tenant::run($account, fn (): StartSyncResult => app(StartShopifySync::class)->selection($site, $picks));

        $this->assertTrue($result->wasTruncated(), 'the merchant must be TOLD what was left out');
        $this->assertSame(1, $result->dropped);
        $this->assertSame(2, $result->cap);

        $run = $result->run;
        $this->assertNotNull($run);
        $this->assertTrue($run->fresh()->isTruncated());
        $this->assertSame(ShopifySyncRun::TRUNCATION_SELECTION_MAX, $run->fresh()->truncated_reason);
        $this->assertSame([self::GID_A, self::GID_B], $run->fresh()->requested_gids);

        // Only the accepted picks were queued — never the ones the bound dropped.
        Bus::assertDispatchedTimes(SyncShopifyProductJob::class, 2);
        Bus::assertNotDispatched(
            SyncShopifyProductJob::class,
            fn (SyncShopifyProductJob $job): bool => $job->gid === 'gid://shopify/Product/999',
        );
    }

    public function test_a_selection_run_completes_once_every_requested_product_is_processed(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeSingleProduct($this->productNode());

        $run = Tenant::run($account, fn (): ShopifySyncRun => app(StartShopifySync::class)
            ->selection($site, [self::GID_A])->run);

        (new SyncShopifyProductJob((int) $account->id, (int) $site->id, self::GID_A, (int) $run->id))->handle();

        $fresh = $run->fresh();
        $this->assertSame(ShopifySyncRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(1, $fresh->imported);
        $this->assertSame(1, Tenant::run($account, fn (): int => Product::count()));
    }

    public function test_a_second_import_all_joins_the_running_walk_instead_of_starting_a_competing_one(): void
    {
        [$account, $site] = $this->connectedShop();

        $this->fakeCatalogCount(2);

        $first = Tenant::run($account, fn () => app(StartShopifySync::class)->catalog($site));
        $second = Tenant::run($account, fn () => app(StartShopifySync::class)->catalog($site));

        $this->assertSame((int) $first->run->id, (int) $second->run->id);
        $this->assertSame(StartSyncResult::OUTCOME_JOINED, $second->outcome);
        Bus::assertDispatchedTimes(SyncShopifyCatalogJob::class, 1);
        $this->assertSame(1, Tenant::run($account, fn (): int => ShopifySyncRun::count()));
    }

    public function test_the_sync_writes_the_timeline_and_stays_inside_its_own_account(): void
    {
        [$accountA, $siteA] = $this->connectedShop();
        [$accountB] = $this->connectedShop('other.myshopify.com');

        $this->fakeCatalog([[[$this->productNode()], false, null]]);

        $run = $this->startCatalog($accountA, $siteA);
        (new SyncShopifyCatalogJob((int) $accountA->id, (int) $siteA->id, (int) $run->id, null))->handle();

        $kinds = Tenant::run($accountA, fn (): array => ActivityEvent::query()->pluck('kind')->all());
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_SYNC_STARTED, $kinds);
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_PRODUCT_IMPORTED, $kinds);
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_SYNC_COMPLETED, $kinds);

        // Account B sees NOTHING of A's run, products or trace (fail-closed global scope).
        Tenant::run($accountB, function (): void {
            $this->assertSame(0, ShopifySyncRun::count());
            $this->assertSame(0, Product::count());
            $this->assertSame(0, ActivityEvent::query()->where('kind', ActivityEvent::KIND_SHOPIFY_PRODUCT_IMPORTED)->count());
        });
    }

    // === HELPERS ===

    /** Open a catalog run the way the merchant does (through the cap guard) and take its run. */
    private function startCatalog($account, $site): ShopifySyncRun
    {
        $result = Tenant::run($account, fn (): StartSyncResult => app(StartShopifySync::class)->catalog($site));

        $this->assertFalse($result->refused(), 'the fixture store must be inside the import cap');

        return $result->run;
    }

    /** Page 1 answers products; every page after it is throttled past the client's budget. */
    /** Shopify throttles the single-product fetch past the client's own retry budget. */
    private function fakeThrottledProduct(): void
    {
        $this->respondWith(fn (Request $request) => Http::response([], 429, ['Retry-After' => '2']));
    }

    private function fakeThrottleAfterFirstPage(): void
    {
        $page = 0;

        $this->respondWith(function (Request $request) use (&$page) {
            $body = json_decode($request->body(), true) ?? [];

            if (str_contains((string) ($body['query'] ?? ''), 'productsCount')) {
                return Http::response(['data' => ['productsCount' => ['count' => 2]]]);
            }

            $page++;

            if ($page === 1) {
                return Http::response(['data' => ['products' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                    'nodes' => [$this->productNode()],
                ]]]);
            }

            return Http::response([], 429, ['Retry-After' => '2']);
        });
    }
}
