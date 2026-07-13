<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\ShopifyLogContext;
use App\Http\Shopify\ShopifyShopRouter;
use App\Models\ShopifyWebhookReceipt;
use Illuminate\Support\Facades\Log;

/**
 * ShopifyWebhookDispatcher — turn a durable receipt into a tenant-bound handler job.
 *
 * The SINGLE dispatch path shared by the intake controller (Phase 2) and the recovery
 * sweep: resolve the topic's handler from config, resolve the owning account via the
 * pre-bind ShopifyShopRouter (only the integer crosses), bump attempts, move the
 * receipt to QUEUED and dispatch the handler with EXPLICIT account_id + receipt id
 * onto the webhooks queue. Unknown topic / unknown shop fail the receipt LOUDLY —
 * a webhook never silently vanishes.
 *
 * Handler contract (Phase 2 jobs): `new Handler(int $accountId, int $receiptId)`;
 * the handler binds the tenant, moves the receipt processing -> processed|failed.
 */
final class ShopifyWebhookDispatcher
{
    // === CONSTANTS ===
    private const CFG_TOPIC_HANDLERS = 'shopify.topic_handlers';

    private const CFG_WEBHOOKS_QUEUE = 'trayon.queues.webhooks';

    private const LOG_QUEUED = 'shopify.webhook.queued';

    private const LOG_FAILED = 'shopify.webhook.failed';

    private const ERROR_NO_HANDLER = 'No handler registered for topic.';

    private const ERROR_UNKNOWN_SHOP = 'Unknown shop domain (no connection).';

    public function __construct(
        private readonly ShopifyShopRouter $router,
    ) {}

    /** Queue the receipt's handler. Returns false when the receipt was failed instead. */
    public function dispatch(ShopifyWebhookReceipt $receipt): bool
    {
        $handler = config(self::CFG_TOPIC_HANDLERS)[$receipt->topic] ?? null;

        if (! is_string($handler) || ! class_exists($handler)) {
            return $this->fail($receipt, self::ERROR_NO_HANDLER);
        }

        $accountId = $this->router->accountIdForShopDomain((string) $receipt->shop_domain);

        if ($accountId === null) {
            return $this->fail($receipt, self::ERROR_UNKNOWN_SHOP);
        }

        $receipt->attempts = (int) $receipt->attempts + 1;
        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_QUEUED);

        $handler::dispatch($accountId, (int) $receipt->getKey())
            ->onQueue((string) config(self::CFG_WEBHOOKS_QUEUE));

        Log::info(self::LOG_QUEUED, ShopifyLogContext::receipt($receipt, ['account_id' => $accountId, 'handler' => $handler]));

        return true;
    }

    private function fail(ShopifyWebhookReceipt $receipt, string $error): bool
    {
        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_FAILED, $error);

        Log::warning(self::LOG_FAILED, ShopifyLogContext::receipt($receipt, ['error' => $error]));

        return false;
    }
}
