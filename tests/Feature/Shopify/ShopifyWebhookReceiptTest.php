<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Webhooks\RecoverStuckShopifyWebhooksJob;
use App\Domain\Shopify\Webhooks\ShopifyWebhookDispatcher;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use App\Models\Site;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The durable webhook inbox: the receipt row (not the queue) is the source of truth.
 * State machine guards, loud failure on unmapped topic / unknown shop, dispatch with
 * an EXPLICIT account_id, the recovery sweep for stuck receipts (bounded attempts),
 * and payload pruning after the retention window.
 */
class ShopifyWebhookReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'products/update';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.topic_handlers', [self::TOPIC => FakeShopifyTopicHandlerJob::class]);
    }

    /** A receipt whose shop_domain routes to a real connection. */
    private function routableReceipt(array $attributes = []): ShopifyWebhookReceipt
    {
        $site = Site::factory()->create();
        $connection = Tenant::run($site->account, fn () => ShopifyConnection::factory()->forSite($site)->create());

        return ShopifyWebhookReceipt::factory()->create(
            $attributes + ['shop_domain' => $connection->shop_domain, 'topic' => self::TOPIC],
        );
    }

    public function test_the_receipt_is_a_global_pre_bind_model_on_the_allow_list(): void
    {
        $this->assertTrue(GlobalModels::isGlobal(ShopifyWebhookReceipt::class));
    }

    public function test_the_bulk_queue_is_registered_in_the_canonical_queue_map(): void
    {
        $this->assertSame('bulk', config('trayon.queues.bulk'));
    }

    public function test_the_state_machine_rejects_illegal_moves(): void
    {
        $receipt = ShopifyWebhookReceipt::factory()->create();

        $this->expectException(RuntimeException::class);
        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_PROCESSING); // received -> processing skips queued
    }

    public function test_processing_to_processed_stamps_processed_at(): void
    {
        $receipt = ShopifyWebhookReceipt::factory()->queued()->create();

        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_PROCESSING);
        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_PROCESSED);

        $this->assertNotNull($receipt->processed_at);
        $this->assertTrue($receipt->isTerminal());
    }

    public function test_dispatch_queues_the_handler_with_the_explicit_account_id(): void
    {
        Bus::fake();
        $receipt = $this->routableReceipt();
        $expectedAccountId = (int) DB::table('shopify_connections')->where('shop_domain', $receipt->shop_domain)->value('account_id');

        $queued = app(ShopifyWebhookDispatcher::class)->dispatch($receipt);

        $this->assertTrue($queued);
        $this->assertSame(ShopifyWebhookReceipt::STATUS_QUEUED, $receipt->status);
        $this->assertSame(1, $receipt->attempts);

        Bus::assertDispatched(FakeShopifyTopicHandlerJob::class, fn (FakeShopifyTopicHandlerJob $job): bool => $job->accountId === $expectedAccountId
            && $job->receiptId === (int) $receipt->id
            && $job->queue === config('trayon.queues.webhooks'));
    }

    public function test_an_unmapped_topic_fails_the_receipt_loudly(): void
    {
        Bus::fake();
        $receipt = $this->routableReceipt(['topic' => 'orders/whatever']);

        $queued = app(ShopifyWebhookDispatcher::class)->dispatch($receipt);

        $this->assertFalse($queued);
        $this->assertSame(ShopifyWebhookReceipt::STATUS_FAILED, $receipt->status);
        $this->assertNotNull($receipt->last_error);
        Bus::assertNothingDispatched();
    }

    public function test_an_unknown_shop_fails_the_receipt_loudly(): void
    {
        Bus::fake();
        $receipt = ShopifyWebhookReceipt::factory()->create(['topic' => self::TOPIC, 'shop_domain' => 'ghost.myshopify.com']);

        $queued = app(ShopifyWebhookDispatcher::class)->dispatch($receipt);

        $this->assertFalse($queued);
        $this->assertSame(ShopifyWebhookReceipt::STATUS_FAILED, $receipt->status);
        Bus::assertNothingDispatched();
    }

    public function test_the_recovery_sweep_redispatches_stuck_receipts_and_bounds_attempts(): void
    {
        Bus::fake();

        // Stuck: queued 10 minutes ago, one attempt so far -> re-dispatched.
        $stuck = $this->routableReceipt(['status' => ShopifyWebhookReceipt::STATUS_QUEUED, 'attempts' => 1]);
        // Exhausted: stuck at the attempt ceiling -> failed, not re-dispatched.
        $exhausted = $this->routableReceipt([
            'status' => ShopifyWebhookReceipt::STATUS_QUEUED,
            'attempts' => (int) config('shopify.receipts.max_attempts'),
        ]);
        // Fresh: recently queued -> untouched.
        $fresh = $this->routableReceipt(['status' => ShopifyWebhookReceipt::STATUS_QUEUED, 'attempts' => 1]);

        DB::table('shopify_webhook_receipts')
            ->whereIn('id', [$stuck->id, $exhausted->id])
            ->update(['updated_at' => now()->subMinutes(10)]);

        (new RecoverStuckShopifyWebhooksJob)->handle(app(ShopifyWebhookDispatcher::class));

        $this->assertSame(ShopifyWebhookReceipt::STATUS_QUEUED, $stuck->fresh()->status);
        $this->assertSame(2, $stuck->fresh()->attempts);
        Bus::assertDispatched(FakeShopifyTopicHandlerJob::class, fn (FakeShopifyTopicHandlerJob $job): bool => $job->receiptId === (int) $stuck->id);

        $this->assertSame(ShopifyWebhookReceipt::STATUS_FAILED, $exhausted->fresh()->status);
        Bus::assertNotDispatched(FakeShopifyTopicHandlerJob::class, fn (FakeShopifyTopicHandlerJob $job): bool => $job->receiptId === (int) $exhausted->id);

        $this->assertSame(1, $fresh->fresh()->attempts);
    }

    public function test_the_recovery_sweep_prunes_old_terminal_payloads_only(): void
    {
        $old = ShopifyWebhookReceipt::factory()->processed()->create();
        $recent = ShopifyWebhookReceipt::factory()->processed()->create();

        DB::table('shopify_webhook_receipts')
            ->where('id', $old->id)
            ->update(['updated_at' => now()->subDays((int) config('shopify.receipts.retention_days') + 1)]);

        (new RecoverStuckShopifyWebhooksJob)->handle(app(ShopifyWebhookDispatcher::class));

        $this->assertNull($old->fresh()->payload);
        $this->assertNotNull($recent->fresh()->payload);
    }
}

/**
 * The Phase-2 handler contract stand-in: (int $accountId, int $receiptId), queueable.
 */
class FakeShopifyTopicHandlerJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $receiptId,
    ) {}

    public function handle(): void
    {
        // Never runs in these tests (Bus is faked).
    }
}
