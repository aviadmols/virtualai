<?php

namespace Tests\Feature\Filament\Merchant;

use App\Filament\Merchant\Pages\ShopifyStore;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merchant "Shopify store" page — the connect / disconnect surface.
 *
 * It issues no token and persists no connection (ShopifyInstaller does, behind the OAuth
 * callback): the page only hands off to the signed OAuth start route, and DISCONNECTS through
 * the guarded transition. What is pinned here: the redirect carries the bound site (never an
 * arbitrary one), a bad shop domain never becomes a redirect, disconnecting wipes the token,
 * and the health counters only ever count THIS shop's webhook receipts.
 */
class ShopifyStorePageTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'my-store.myshopify.com';

    private const OTHER_SHOP = 'other-store.myshopify.com';

    private Account $account;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        // The platform app credentials — without them the page renders the "not configured" state.
        config()->set('services.shopify.client_id', 'test-client-id');
        config()->set('services.shopify.client_secret', 'test-client-secret');

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->account)->create());
        Filament::setTenant($this->site);
    }

    public function test_the_page_renders_the_empty_state_when_no_store_is_connected(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ShopifyStore::class)
                ->assertOk()
                ->assertSee(__('shopify.disconnected.heading'));
        });
    }

    public function test_connecting_redirects_to_the_oauth_start_route_for_the_bound_site(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ShopifyStore::class)
                ->callAction('connect', ['shop' => self::SHOP])
                ->assertRedirect(route('shopify.oauth.start', [
                    'shop' => self::SHOP,
                    'site' => $this->site->getKey(),
                ]));
        });

        // The page itself never writes a connection — only the OAuth callback does.
        $this->assertDatabaseCount('shopify_connections', 0);
    }

    public function test_a_non_myshopify_domain_is_rejected_before_any_redirect(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ShopifyStore::class)
                ->callAction('connect', ['shop' => 'evil.example.com'])
                ->assertHasActionErrors(['shop'])
                ->assertNoRedirect();
        });
    }

    public function test_disconnecting_wipes_the_token_and_keeps_the_row(): void
    {
        $connection = ShopifyConnection::factory()->forSite($this->site)->create([
            'shop_domain' => self::SHOP,
        ]);

        Tenant::run($this->account->id, function (): void {
            Livewire::test(ShopifyStore::class)
                ->assertSee(self::SHOP)
                ->callAction('disconnect')
                ->assertHasNoActionErrors();
        });

        $connection->refresh();

        $this->assertSame(ShopifyConnection::STATUS_UNINSTALLED, $connection->status);
        $this->assertNull($connection->accessToken());
        $this->assertDatabaseCount('shopify_connections', 1); // a re-connect re-activates THIS row
    }

    public function test_webhook_health_counts_only_this_shops_receipts(): void
    {
        ShopifyConnection::factory()->forSite($this->site)->create([
            'shop_domain' => self::SHOP,
            'webhook_registration' => ['products/update' => 'gid://shopify/WebhookSubscription/1'],
        ]);

        ShopifyWebhookReceipt::factory()->create([
            'shop_domain' => self::SHOP,
            'status' => ShopifyWebhookReceipt::STATUS_FAILED,
        ]);

        // Another store's failures must never show up on this merchant's health panel.
        ShopifyWebhookReceipt::factory()->count(3)->create([
            'shop_domain' => self::OTHER_SHOP,
            'status' => ShopifyWebhookReceipt::STATUS_FAILED,
        ]);

        Tenant::run($this->account->id, function (): void {
            $health = Livewire::test(ShopifyStore::class)->instance()->webhookHealth();

            $this->assertSame(1, $health['failed']);
            $this->assertSame(['products/update'], $health['topics']);
        });
    }
}
