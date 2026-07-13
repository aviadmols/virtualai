<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Webhooks\AcknowledgeGdprWebhookJob;
use App\Domain\Shopify\Webhooks\HandleAppUninstalledJob;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The tenant-bound topic handlers. HandleAppUninstalledJob is the money-adjacent one: the
 * offline token is DEAD the moment Shopify fires app/uninstalled, so the guarded
 * transition WIPES the encrypted credentials, keeps the row (shop_domain is the routing
 * key, so a re-install re-activates rather than duplicates), and leaves an activity
 * event. The receipt state machine is driven by the shared base class, so a replay can
 * never re-run the work.
 */
class ShopifyWebhookHandlersTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHOP = 'demo-shop.myshopify.com';

    public function test_app_uninstalled_wipes_the_credentials_and_keeps_the_row(): void
    {
        [$account, $site, $connection] = $this->connectedShop();
        $receipt = $this->queuedReceipt('app/uninstalled');

        (new HandleAppUninstalledJob((int) $account->id, (int) $receipt->id))->handle();

        $fresh = Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertSame((int) $connection->id, (int) $fresh->id); // the row survives
        $this->assertSame(ShopifyConnection::STATUS_UNINSTALLED, $fresh->status);
        $this->assertNull($fresh->accessToken());
        $this->assertNull(DB::table('shopify_connections')->where('id', $connection->id)->value('credentials'));
        $this->assertNotNull($fresh->uninstalled_at);

        // The receipt is terminal, and the timeline carries the event.
        $this->assertSame(ShopifyWebhookReceipt::STATUS_PROCESSED, $receipt->fresh()->status);
        $this->assertNotNull($receipt->fresh()->processed_at);

        $event = Tenant::run($account, fn (): ?ActivityEvent => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_SHOPIFY_UNINSTALLED)
            ->first());
        $this->assertNotNull($event);
        $this->assertSame((int) $account->id, (int) $event->account_id);

        // The worker leaks no tenant into the next job.
        $this->assertFalse(Tenant::check());
    }

    public function test_a_replayed_uninstall_receipt_is_a_no_op(): void
    {
        [$account] = $this->connectedShop();
        $receipt = $this->queuedReceipt('app/uninstalled');

        (new HandleAppUninstalledJob((int) $account->id, (int) $receipt->id))->handle();
        // A duplicated dispatch of a PROCESSED receipt must not re-run anything.
        (new HandleAppUninstalledJob((int) $account->id, (int) $receipt->id))->handle();

        $this->assertSame(ShopifyWebhookReceipt::STATUS_PROCESSED, $receipt->fresh()->status);

        $events = Tenant::run($account, fn (): int => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_SHOPIFY_UNINSTALLED)
            ->count());
        $this->assertSame(1, $events); // exactly one uninstall on the timeline
    }

    public function test_an_uninstall_for_another_accounts_shop_touches_nothing(): void
    {
        [$accountA] = $this->connectedShop();
        $accountB = Account::factory()->create();
        $receipt = $this->queuedReceipt('app/uninstalled');

        // A handler bound to account B cannot see (and so cannot uninstall) A's
        // connection: BelongsToAccount fails closed.
        (new HandleAppUninstalledJob((int) $accountB->id, (int) $receipt->id))->handle();

        $connection = Tenant::run($accountA, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertSame(ShopifyConnection::STATUS_INSTALLED, $connection->status);
        $this->assertNotNull($connection->accessToken()); // A's token is untouched
    }

    public function test_the_gdpr_handler_receipts_the_request_without_erasing_anything(): void
    {
        [$account] = $this->connectedShop();
        $receipt = $this->queuedReceipt('customers/redact');

        (new AcknowledgeGdprWebhookJob((int) $account->id, (int) $receipt->id))->handle();

        $this->assertSame(ShopifyWebhookReceipt::STATUS_PROCESSED, $receipt->fresh()->status);
        // The payload is retained (Phase 7 wires the real erasure off this durable row).
        $this->assertNotNull($receipt->fresh()->payload);
        $this->assertFalse(Tenant::check());
    }

    // === HELPERS ===

    /** @return array{0: Account, 1: Site, 2: ShopifyConnection} */
    private function connectedShop(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create(['platform' => Site::PLATFORM_SHOPIFY]);
        $connection = Tenant::run($account, fn (): ShopifyConnection => ShopifyConnection::factory()
            ->forSite($site)
            ->create(['shop_domain' => self::SHOP]));

        return [$account, $site, $connection];
    }

    /** A receipt in the state the dispatcher hands to a handler. */
    private function queuedReceipt(string $topic): ShopifyWebhookReceipt
    {
        return ShopifyWebhookReceipt::factory()->queued()->create([
            'topic' => $topic,
            'shop_domain' => self::SHOP,
        ]);
    }
}
