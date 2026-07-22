<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Auth\ShopifyAccessToken;
use App\Domain\Shopify\Auth\ShopifyInstaller;
use App\Domain\Shopify\Auth\ShopifyOAuthState;
use App\Domain\Shopify\Metafields\SyncShopMetafieldsJob;
use App\Domain\Shopify\Webhooks\RegisterShopifyWebhooksJob;
use App\Domain\Sites\SiteKeyRegenerator;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SyncShopMetafieldsJob — the automatic theme-extension configuration. The job writes the
 * site's PUBLIC key to the app-owned shop metafield ($app:settings/site_key) so the Liquid
 * blocks self-configure; it must be CONVERGENT (zero API calls once the stored marker equals
 * the current key), re-sync after a key rotation, never run for an uninstalled shop, and
 * never write the widget_secret anywhere. Dispatch points: installer connect (which also
 * allow-lists the store's own origin) and site-key rotation.
 */
class SyncShopMetafieldsJobTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHOP = 'demo-shop.myshopify.com';

    private const GRAPHQL_URL = 'https://demo-shop.myshopify.com/admin/api/*/graphql.json';

    private const SHOP_GID = 'gid://shopify/Shop/77';

    // shop-id query + metafieldsSet mutation = one sync pass.
    private const CALLS_PER_SYNC = 2;

    public function test_it_writes_the_site_key_to_the_app_owned_shop_metafield(): void
    {
        $this->fakeSyncOk(self::CALLS_PER_SYNC);
        [$account, $site] = $this->connectedShop();

        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();

        Http::assertSentCount(self::CALLS_PER_SYNC);

        // The mutation carries the PUBLIC key into the app-reserved namespace — and never the secret.
        Http::assertSent(function (Request $request) use ($site): bool {
            $body = (array) json_decode((string) $request->body(), true);
            $metafield = (array) ($body['variables']['metafields'][0] ?? []);

            return str_contains((string) ($body['query'] ?? ''), 'metafieldsSet')
                && ($metafield['ownerId'] ?? null) === self::SHOP_GID
                && ($metafield['namespace'] ?? null) === SyncShopMetafieldsJob::NAMESPACE
                && ($metafield['key'] ?? null) === SyncShopMetafieldsJob::KEY_SITE_KEY
                && ($metafield['value'] ?? null) === (string) $site->site_key;
        });

        $this->assertSame((string) $site->site_key, $this->connection($account)->metafields_synced_key);
        $this->assertFalse(Tenant::check());
    }

    public function test_a_converged_connection_makes_no_api_calls(): void
    {
        $this->fakeSyncOk(self::CALLS_PER_SYNC);
        [$account, $site] = $this->connectedShop();

        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();
        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();

        // The second run found the marker equal to the current key: zero extra calls.
        Http::assertSentCount(self::CALLS_PER_SYNC);
    }

    public function test_a_rotated_key_re_syncs_the_metafield(): void
    {
        $this->fakeSyncOk(self::CALLS_PER_SYNC * 2);
        [$account, $site] = $this->connectedShop();

        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();

        // The merchant rotated the public key: the marker no longer matches -> re-sync.
        Tenant::run($account, fn () => $site->forceFill(['site_key' => Site::generateSiteKey()])->save());
        $site->refresh();

        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();

        Http::assertSentCount(self::CALLS_PER_SYNC * 2);
        $this->assertSame((string) $site->site_key, $this->connection($account)->metafields_synced_key);
    }

    public function test_an_uninstalled_connection_is_skipped(): void
    {
        Http::fake();
        [$account, $site] = $this->connectedShop(ShopifyConnection::STATUS_UNINSTALLED);

        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();

        Http::assertNothingSent();
    }

    public function test_a_user_error_records_no_marker_so_the_next_trigger_retries(): void
    {
        Http::fake([
            self::GRAPHQL_URL => Http::sequence()
                ->push(['data' => ['shop' => ['id' => self::SHOP_GID]]])
                ->push(['data' => ['metafieldsSet' => [
                    'metafields' => [],
                    'userErrors' => [['field' => ['namespace'], 'message' => 'Namespace is invalid']],
                ]]]),
        ]);
        [$account, $site] = $this->connectedShop();

        (new SyncShopMetafieldsJob((int) $account->id, (int) $site->id))->handle();

        $this->assertNull($this->connection($account)->metafields_synced_key);
    }

    public function test_the_job_is_unique_per_account_and_site(): void
    {
        $job = new SyncShopMetafieldsJob(7, 9);

        $this->assertSame('shopify-metafields:7:9', $job->uniqueId());
        $this->assertSame(config('trayon.queues.webhooks'), $job->queue);
    }

    // --- Dispatch wiring ---

    public function test_connect_dispatches_the_sync_and_allows_the_shops_own_origin(): void
    {
        Queue::fake();
        $account = Account::factory()->create();
        // A panel-created site with its own (custom) domain — the myshopify origin would 403.
        $site = Site::factory()->forAccount($account)->create(['domain' => 'www.mybrand.com']);

        app(ShopifyInstaller::class)->connect(
            accountId: (int) $account->id,
            siteId: (int) $site->id,
            shopDomain: self::SHOP,
            token: new ShopifyAccessToken('shpat_test', 'read_products', (string) config('shopify.api_version')),
            flow: ShopifyOAuthState::FLOW_CONNECT_EXISTING_SITE,
        );

        Queue::assertPushed(SyncShopMetafieldsJob::class, fn (SyncShopMetafieldsJob $job): bool => $job->accountId === (int) $account->id && $job->siteId === (int) $site->id);
        Queue::assertPushed(RegisterShopifyWebhooksJob::class);

        // The storefront's own origin now passes the widget Origin allow-list.
        $site->refresh();
        $this->assertContains('https://'.self::SHOP, (array) $site->allowed_origins);
    }

    public function test_key_rotation_dispatches_the_sync_for_a_shopify_site(): void
    {
        Queue::fake();
        [$account, $site] = $this->connectedShop();

        Tenant::run($account, fn (): string => app(SiteKeyRegenerator::class)->regenerate($site));

        Queue::assertPushed(SyncShopMetafieldsJob::class, fn (SyncShopMetafieldsJob $job): bool => $job->siteId === (int) $site->id);
    }

    // === HELPERS ===

    /** @return array{0: Account, 1: Site} */
    private function connectedShop(string $status = ShopifyConnection::STATUS_INSTALLED): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create(['platform' => Site::PLATFORM_SHOPIFY]);

        Tenant::run($account, fn () => ShopifyConnection::factory()->forSite($site)->create([
            'shop_domain' => self::SHOP,
            'status' => $status,
        ]));

        return [$account, $site->refresh()];
    }

    private function connection(Account $account): ShopifyConnection
    {
        return Tenant::run($account, fn (): ShopifyConnection => ShopifyConnection::query()->firstOrFail());
    }

    /** Alternate shop-id + metafieldsSet-ok responses for $calls total requests. */
    private function fakeSyncOk(int $calls): void
    {
        $sequence = Http::sequence();

        for ($i = 0; $i < $calls; $i++) {
            $sequence->push($i % 2 === 0
                ? ['data' => ['shop' => ['id' => self::SHOP_GID]]]
                : ['data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]]]);
        }

        Http::fake([self::GRAPHQL_URL => $sequence]);
    }
}
