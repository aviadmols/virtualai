<?php

namespace Tests\Feature\Shopify;

use App\Http\Shopify\ShopifyShopRouter;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pre-bind routing lookup: shop_domain -> the owning account_id (an integer,
 * nothing else), WITHOUT a bound tenant — the SiteRouter shape for Shopify webhooks.
 */
class ShopifyShopRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_owning_account_id_pre_bind(): void
    {
        $site = Site::factory()->create();
        $connection = Tenant::run($site->account, fn () => ShopifyConnection::factory()->forSite($site)->create());

        // NO tenant bound — this is the whole point of the router.
        $resolved = app(ShopifyShopRouter::class)->accountIdForShopDomain($connection->shop_domain);

        $this->assertSame((int) $site->account_id, $resolved);
    }

    public function test_unknown_or_empty_shop_domains_resolve_to_null(): void
    {
        $router = app(ShopifyShopRouter::class);

        $this->assertNull($router->accountIdForShopDomain('ghost.myshopify.com'));
        $this->assertNull($router->accountIdForShopDomain(''));
    }
}
