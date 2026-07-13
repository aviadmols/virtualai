<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Webhooks\RegisterShopifyWebhooksJob;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RegisterShopifyWebhooksJob — subscribing the store to config('shopify.topics') must be
 * IDEMPOTENT: a re-install, a queue retry, or a double-clicked Connect can never create
 * duplicate subscriptions. Two walls: topics already in webhook_registration are skipped
 * (zero API calls), and Shopify's own "address already taken" userError converges instead
 * of retrying forever. The call is version-pinned and carries the token in the header —
 * never in a URL, never in a log.
 */
class RegisterShopifyWebhooksJobTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHOP = 'demo-shop.myshopify.com';

    private const GRAPHQL_URL = 'https://demo-shop.myshopify.com/admin/api/*/graphql.json';

    private const SUBSCRIPTION_ID = 'gid://shopify/WebhookSubscription/1';

    public function test_it_registers_every_configured_topic_and_stores_the_subscription_ids(): void
    {
        $this->fakeCreateOk();
        [$account, $site] = $this->connectedShop();

        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();

        $topics = (array) config('shopify.topics');
        $registration = (array) $this->connection($account)->webhook_registration;

        $this->assertSame($topics, array_keys($registration));
        foreach ($registration as $id) {
            $this->assertSame(self::SUBSCRIPTION_ID, $id);
        }

        Http::assertSentCount(count($topics));

        // Version-pinned endpoint + the token in the header (never in the URL).
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/admin/api/'.config('shopify.api_version').'/graphql.json')
            && $request->hasHeader('X-Shopify-Access-Token')
            && ! str_contains($request->url(), 'shpat_'));

        // The tenant is not left bound on the worker.
        $this->assertFalse(Tenant::check());
    }

    public function test_a_second_run_makes_no_api_calls_at_all(): void
    {
        $this->fakeCreateOk();
        [$account, $site] = $this->connectedShop();

        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();
        $callsAfterFirst = count((array) config('shopify.topics'));
        Http::assertSentCount($callsAfterFirst);

        // Re-install / retry: every topic is already registered -> nothing is re-created.
        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();

        Http::assertSentCount($callsAfterFirst);
        $this->assertCount($callsAfterFirst, (array) $this->connection($account)->webhook_registration);
    }

    public function test_shopifys_already_taken_user_error_converges_instead_of_retrying(): void
    {
        Http::fake([
            self::GRAPHQL_URL => Http::response([
                'data' => [
                    'webhookSubscriptionCreate' => [
                        'webhookSubscription' => null,
                        'userErrors' => [['field' => ['topic'], 'message' => 'Address for this topic has already been taken']],
                    ],
                ],
            ]),
        ]);
        [$account, $site] = $this->connectedShop();

        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();

        $registration = (array) $this->connection($account)->webhook_registration;
        $this->assertSame(RegisterShopifyWebhooksJob::REGISTRATION_EXISTING, $registration['app/uninstalled']);

        // The subscription exists on Shopify's side: a second run must not call again.
        $calls = count((array) config('shopify.topics'));
        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();
        Http::assertSentCount($calls);
    }

    public function test_an_api_failure_records_nothing_and_leaves_the_topic_for_the_next_run(): void
    {
        Http::fake([self::GRAPHQL_URL => Http::response(['errors' => [['message' => 'Throttled']]], 429)]);
        [$account, $site] = $this->connectedShop();

        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();

        // No half-recorded registration: the next run retries the topic cleanly.
        $this->assertSame([], (array) $this->connection($account)->webhook_registration);
    }

    public function test_an_uninstalled_connection_is_never_registered(): void
    {
        Http::fake();
        [$account, $site] = $this->connectedShop(ShopifyConnection::STATUS_UNINSTALLED);

        (new RegisterShopifyWebhooksJob((int) $account->id, (int) $site->id))->handle();

        Http::assertNothingSent();
    }

    public function test_the_job_is_unique_per_account_and_site(): void
    {
        $job = new RegisterShopifyWebhooksJob(7, 9);

        $this->assertSame('shopify-webhooks:7:9', $job->uniqueId());
        $this->assertSame(config('trayon.queues.webhooks'), $job->queue);
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

        return [$account, $site];
    }

    private function connection(Account $account): ShopifyConnection
    {
        return Tenant::run($account, fn (): ShopifyConnection => ShopifyConnection::query()->firstOrFail());
    }

    private function fakeCreateOk(): void
    {
        Http::fake([
            self::GRAPHQL_URL => Http::response([
                'data' => [
                    'webhookSubscriptionCreate' => [
                        'webhookSubscription' => ['id' => self::SUBSCRIPTION_ID],
                        'userErrors' => [],
                    ],
                ],
            ]),
        ]);
    }
}
