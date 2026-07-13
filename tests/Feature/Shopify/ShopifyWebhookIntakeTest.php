<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Webhooks\AcknowledgeGdprWebhookJob;
use App\Domain\Shopify\Webhooks\HandleAppUninstalledJob;
use App\Domain\Shopify\Webhooks\ShopifyWebhookVerifier;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The webhook intake: verify the RAW-BODY HMAC (fail closed -> 401, and NOT even a
 * receipt row), receipt the delivery durably, dedupe on X-Shopify-Webhook-Id (a replay
 * is a 200 that dispatches nothing), and hand off to the dispatcher with an EXPLICIT
 * account_id. The three mandatory GDPR topics answer 200 through the same intake from
 * day one. Shopify must always get its 200 fast — even for an unmapped topic, which
 * fails the RECEIPT (durable + replayable) instead of vanishing.
 */
class ShopifyWebhookIntakeTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_SECRET = 'test-shopify-client-secret';

    private const SHOP = 'demo-shop.myshopify.com';

    private const TOPIC_UNINSTALLED = 'app/uninstalled';

    private const TOPIC_GDPR = 'customers/redact';

    private const WEBHOOK_ID = 'wh-0001';

    private const PATH = '/shopify/webhooks';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);
    }

    public function test_a_signed_delivery_creates_a_receipt_and_dispatches_with_the_explicit_account_id(): void
    {
        Bus::fake();
        [$account] = $this->connectedShop();

        $response = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'duplicate' => false]);

        $receipt = ShopifyWebhookReceipt::query()->firstOrFail();
        $this->assertSame(self::WEBHOOK_ID, $receipt->webhook_id);
        $this->assertSame(self::TOPIC_UNINSTALLED, $receipt->topic);
        $this->assertSame(self::SHOP, $receipt->shop_domain);
        $this->assertSame(ShopifyWebhookReceipt::STATUS_QUEUED, $receipt->status);
        $this->assertNotNull($receipt->correlation_id); // minted at the inbound edge

        Bus::assertDispatched(
            HandleAppUninstalledJob::class,
            fn (HandleAppUninstalledJob $job): bool => $job->accountId === (int) $account->id
                && $job->receiptId === (int) $receipt->id
                && $job->queue === config('trayon.queues.webhooks'),
        );
    }

    public function test_a_bad_signature_is_401_and_writes_no_receipt(): void
    {
        Bus::fake();
        $this->connectedShop();

        $response = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1], signature: 'forged-signature');

        $response->assertStatus(401);
        $this->assertSame(0, ShopifyWebhookReceipt::query()->count()); // the inbox stays clean
        Bus::assertNothingDispatched();
    }

    public function test_a_missing_signature_is_401_and_writes_no_receipt(): void
    {
        Bus::fake();
        $this->connectedShop();

        $response = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1], signature: '');

        $response->assertStatus(401);
        $this->assertSame(0, ShopifyWebhookReceipt::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_a_delivery_from_a_non_myshopify_host_is_rejected(): void
    {
        Bus::fake();
        $this->connectedShop();

        $response = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1], shop: 'evil.example.com');

        $response->assertStatus(401);
        $this->assertSame(0, ShopifyWebhookReceipt::query()->count());
    }

    public function test_a_replayed_webhook_id_processes_exactly_once(): void
    {
        Bus::fake();
        $this->connectedShop();

        $first = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1]);
        $replay = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1]);

        $first->assertOk()->assertJson(['duplicate' => false]);
        // Shopify gets its 200 (it must stop retrying) but nothing is re-dispatched.
        $replay->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);

        $this->assertSame(1, ShopifyWebhookReceipt::query()->count());
        Bus::assertDispatchedTimes(HandleAppUninstalledJob::class, 1);
    }

    public function test_the_gdpr_topics_are_receipted_and_answered_200_from_day_one(): void
    {
        Bus::fake();
        [$account] = $this->connectedShop();

        foreach ((array) config('shopify.gdpr_topics') as $index => $topic) {
            $response = $this->deliver((string) $topic, ['shop_id' => 1], webhookId: 'gdpr-'.$index);
            $response->assertOk();
        }

        $this->assertSame(3, ShopifyWebhookReceipt::query()->count());
        Bus::assertDispatchedTimes(AcknowledgeGdprWebhookJob::class, 3);
        Bus::assertDispatched(
            AcknowledgeGdprWebhookJob::class,
            fn (AcknowledgeGdprWebhookJob $job): bool => $job->accountId === (int) $account->id,
        );
    }

    public function test_an_unmapped_topic_still_answers_200_but_fails_the_receipt_loudly(): void
    {
        Bus::fake();
        $this->connectedShop();

        $response = $this->deliver('orders/create', ['id' => 1]);

        $response->assertOk(); // never make Shopify retry something we durably recorded
        $receipt = ShopifyWebhookReceipt::query()->firstOrFail();
        $this->assertSame(ShopifyWebhookReceipt::STATUS_FAILED, $receipt->status);
        $this->assertNotNull($receipt->last_error);
        Bus::assertNothingDispatched();
    }

    public function test_an_unknown_shop_answers_200_and_fails_the_receipt(): void
    {
        Bus::fake(); // no connection at all -> the pre-bind router resolves no account

        $response = $this->deliver(self::TOPIC_UNINSTALLED, ['shop_id' => 1]);

        $response->assertOk();
        $this->assertSame(ShopifyWebhookReceipt::STATUS_FAILED, ShopifyWebhookReceipt::query()->firstOrFail()->status);
        Bus::assertNothingDispatched();
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

    /** POST a delivery with a genuine (or deliberately broken) raw-body signature. */
    private function deliver(
        string $topic,
        array $payload,
        ?string $signature = null,
        string $shop = self::SHOP,
        string $webhookId = self::WEBHOOK_ID,
    ) {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature ??= ShopifyWebhookVerifier::signature($raw, self::CLIENT_SECRET);

        return $this->call(
            method: 'POST',
            uri: self::PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SHOPIFY_TOPIC' => $topic,
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature,
            ],
            content: $raw,
        );
    }
}
