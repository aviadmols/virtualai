<?php

namespace Tests\Feature\Shopify;

use App\Domain\Products\PersistProduct;
use App\Domain\Shopify\Products\ShopifyProductMapper;
use App\Domain\Shopify\Webhooks\HandleProductDeleteJob;
use App\Domain\Shopify\Webhooks\HandleProductUpdateJob;
use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ShopifyWebhookReceipt;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The products/update + products/delete topic handlers.
 *
 * TWO MUTATION-VERIFIED GUARDS:
 *  1. test_a_products_update_of_a_confirmed_product_never_resets_its_status — delete the
 *     `if (! $statusPreserved)` guard in PersistProduct and a webhook silently re-drafts
 *     a live product (it vanishes from the widget). Red.
 *  2. test_products_delete_archives_the_product_and_never_deletes_it — swap archive() for
 *     delete() in ShopifyProductImporter::archiveByGid and the past generation's
 *     product_id/variant_id are blown away (nullOnDelete). Red.
 *
 * The webhook payload is treated as a SIGNAL, not as data: the handler re-reads the
 * product through the Admin API, which is why every test here fakes the GraphQL call.
 */
class ShopifyProductWebhookTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    private const PRODUCT_NUMERIC_ID = '1001';

    public function test_the_topic_handlers_are_registered_with_the_locked_handler_contract(): void
    {
        $handlers = (array) config('shopify.topic_handlers');

        $this->assertSame(HandleProductUpdateJob::class, $handlers['products/update'] ?? null);
        $this->assertSame(HandleProductDeleteJob::class, $handlers['products/delete'] ?? null);

        // The dispatcher's contract: new Handler(int $accountId, int $receiptId).
        foreach ([HandleProductUpdateJob::class, HandleProductDeleteJob::class] as $class) {
            $parameters = (new \ReflectionClass($class))->getConstructor()->getParameters();

            $this->assertSame('accountId', $parameters[0]->getName());
            $this->assertSame('receiptId', $parameters[1]->getName());
        }
    }

    public function test_a_products_update_refreshes_the_data_of_a_draft_product(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));

        // The push is only a SIGNAL: the handler re-reads the product through the API.
        $this->fakeSingleProduct($this->productNode(['title' => 'Merino Crew (Restyled)']));
        $receipt = $this->receipt('products/update');

        (new HandleProductUpdateJob((int) $account->id, (int) $receipt->id))->handle();

        $this->assertSame('Merino Crew (Restyled)', $product->fresh()->name);
        $this->assertSame(ShopifyWebhookReceipt::STATUS_PROCESSED, $receipt->fresh()->status);
        $this->assertFalse(Tenant::check());
    }

    /** MUTATION-VERIFIED: the refresh-confirmed law. */
    public function test_a_products_update_of_a_confirmed_product_never_resets_its_status(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));
        Tenant::run($account, fn () => $product->fresh()->confirm());

        $this->fakeSingleProduct($this->productNode(['title' => 'Merino Crew (Restyled)']));
        $receipt = $this->receipt('products/update');

        (new HandleProductUpdateJob((int) $account->id, (int) $receipt->id))->handle();

        $fresh = $product->fresh();

        $this->assertSame(Product::STATUS_CONFIRMED, $fresh->status, 'a webhook may NEVER un-confirm a live product');
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertSame('Merino Crew (Restyled)', $fresh->name, 'the DATA is still refreshed');
    }

    /**
     * MUTATION-VERIFIED: make PersistProduct set `is_active = true` unconditionally again
     * and this goes RED.
     *
     * THE FLAP. The catalog walk only asks for `status:active`, so an unpublished product
     * is archived locally. Shopify then pushes products/update for that same save — and a
     * writer that treats "the API returned the product" as "the store still sells it"
     * RE-ACTIVATES it. The product bounces back into the widget one webhook after the walk
     * removed it, and every subsequent save flips it again. The store's own status is the
     * truth; the writer honours it.
     */
    public function test_an_unpublished_product_is_not_re_activated_by_the_next_update_webhook(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));
        $this->assertTrue($product->is_active);

        // The merchant unpublishes it; the status:active walk archives it locally.
        Tenant::run($account, fn () => $product->fresh()->archive());

        // Shopify pushes products/update for the very same save. The re-read says: DRAFT.
        $this->fakeSingleProduct($this->productNode(['status' => 'DRAFT']));
        $receipt = $this->receipt('products/update');

        (new HandleProductUpdateJob((int) $account->id, (int) $receipt->id))->handle();

        $fresh = $product->fresh();

        $this->assertFalse($fresh->is_active, 'an unpublished product must NOT flap back into the widget');
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('Merino Crew Sweater', $fresh->name, 'the DATA is still refreshed');
    }

    /** ...and re-publishing brings it back. The store is the truth in BOTH directions. */
    public function test_a_republished_product_returns_on_the_next_update_webhook(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));
        Tenant::run($account, fn () => $product->fresh()->archive());

        $this->fakeSingleProduct($this->productNode()); // ACTIVE again
        $receipt = $this->receipt('products/update');

        (new HandleProductUpdateJob((int) $account->id, (int) $receipt->id))->handle();

        $fresh = $product->fresh();

        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->archived_at);
    }

    /** MUTATION-VERIFIED: archive, never delete. */
    public function test_products_delete_archives_the_product_and_never_deletes_it(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));
        $receipt = $this->receipt('products/delete');

        (new HandleProductDeleteJob((int) $account->id, (int) $receipt->id))->handle();

        $row = DB::table('products')->where('id', $product->getKey())->first();

        $this->assertNotNull($row, 'the product row must survive — generations and the gallery FK it');
        $this->assertSame(0, (int) $row->is_active);
        $this->assertNotNull($row->archived_at);
        // The status machine is untouched: a delete is a lifecycle fact, not a scan outcome.
        $this->assertSame(Product::STATUS_DRAFT, $row->status);

        $kinds = Tenant::run($account, fn (): array => ActivityEvent::query()->pluck('kind')->all());
        $this->assertContains(ActivityEvent::KIND_SHOPIFY_PRODUCT_ARCHIVED, $kinds);
    }

    public function test_a_replayed_delete_is_a_no_op(): void
    {
        [$account, $site] = $this->connectedShop();

        Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));

        $first = $this->receipt('products/delete');
        (new HandleProductDeleteJob((int) $account->id, (int) $first->id))->handle();

        $second = $this->receipt('products/delete', 'wh_replay');
        (new HandleProductDeleteJob((int) $account->id, (int) $second->id))->handle();

        $events = Tenant::run($account, fn (): int => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_SHOPIFY_PRODUCT_ARCHIVED)
            ->count());

        $this->assertSame(1, $events, 'exactly one archive on the timeline');
        $this->assertSame(ShopifyWebhookReceipt::STATUS_PROCESSED, $second->fresh()->status);
    }

    public function test_a_handler_bound_to_another_account_cannot_touch_this_products_row(): void
    {
        [$accountA, $siteA] = $this->connectedShop();
        [$accountB] = $this->connectedShop('other.myshopify.com');

        $product = Tenant::run($accountA, fn (): Product => $this->import($siteA, $this->productNode()));

        // The receipt names A's shop, but the job is (wrongly) bound to account B: the
        // BelongsToAccount global scope fails CLOSED — B resolves no connection, no site,
        // no product. A's row is untouched.
        $receipt = $this->receipt('products/delete');
        (new HandleProductDeleteJob((int) $accountB->id, (int) $receipt->id))->handle();

        $this->assertTrue($product->fresh()->is_active, "account B must not be able to archive A's product");
        $this->assertNull($product->fresh()->archived_at);
    }

    public function test_a_product_deleted_between_the_push_and_our_read_is_archived_not_retried(): void
    {
        [$account, $site] = $this->connectedShop();

        $product = Tenant::run($account, fn (): Product => $this->import($site, $this->productNode()));

        // The Admin API answers "no such product" (it was deleted right after the push).
        $this->fakeSingleProduct(null);
        $receipt = $this->receipt('products/update');

        (new HandleProductUpdateJob((int) $account->id, (int) $receipt->id))->handle();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(ShopifyWebhookReceipt::STATUS_PROCESSED, $receipt->fresh()->status, 'no retry storm');
    }

    // === HELPERS ===

    private function receipt(string $topic, string $webhookId = 'wh_1'): ShopifyWebhookReceipt
    {
        return ShopifyWebhookReceipt::factory()->queued()->create([
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'shop_domain' => self::SHOP,
            'payload' => ['id' => self::PRODUCT_NUMERIC_ID, 'title' => 'whatever the push says'],
        ]);
    }

    /** Seed a persisted, imported product WITHOUT touching the API stub the test set. */
    private function import($site, array $node): Product
    {
        $mapper = app(ShopifyProductMapper::class);

        return app(PersistProduct::class)->persist(
            $site,
            $mapper->map($node, self::SHOP),
            $mapper->origin($node, self::SHOP)->toOrigin(),
        )->product;
    }
}
