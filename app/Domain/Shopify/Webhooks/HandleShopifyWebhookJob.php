<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\ShopifyLogContext;
use App\Jobs\TenantAwareJob;
use App\Models\ShopifyWebhookReceipt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HandleShopifyWebhookJob — the base every topic handler extends. It IS the handler
 * contract the ShopifyWebhookDispatcher dispatches against: (int $accountId, int
 * $receiptId), tenant-bound, on the webhooks queue.
 *
 * It owns the receipt half of the state machine so no topic handler can forget it:
 *   queued -> processing -> processed        (handleTopic() returned)
 *   queued -> processing -> failed           (handleTopic() threw; the error is recorded
 *                                             and RE-THROWN so the worker/Horizon sees it)
 * A receipt that is already terminal (processed) or not in a dispatchable state is a
 * NO-OP — a replayed/duplicated dispatch can never re-run the work.
 *
 * Subclasses implement handleTopic() and run with the correct tenant already bound
 * (TenantAwareJob::handle wraps it in Tenant::run, which clears in finally).
 */
abstract class HandleShopifyWebhookJob extends TenantAwareJob
{
    // === CONSTANTS ===
    private const CFG_QUEUE = 'trayon.queues.webhooks';

    private const LOG_MISSING = 'shopify.webhook.receipt_missing';

    private const LOG_SKIPPED = 'shopify.webhook.receipt_not_dispatchable';

    private const LOG_HANDLED = 'shopify.webhook.processed';

    private const LOG_FAILED = 'shopify.webhook.handler_failed';

    // The states a dispatched handler may pick up: QUEUED (the normal path) and
    // PROCESSING (a retry of a job that died mid-flight).
    private const DISPATCHABLE = [
        ShopifyWebhookReceipt::STATUS_QUEUED,
        ShopifyWebhookReceipt::STATUS_PROCESSING,
    ];

    public function __construct(
        int $accountId,
        public readonly int $receiptId,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    final protected function process(): void
    {
        $receipt = ShopifyWebhookReceipt::query()->find($this->receiptId);

        if ($receipt === null) {
            Log::warning(self::LOG_MISSING, ['receipt_id' => $this->receiptId, 'account_id' => $this->accountId]);

            return;
        }

        if (! in_array((string) $receipt->status, self::DISPATCHABLE, true)) {
            // Already processed (a replay), or failed and awaiting the recovery sweep.
            Log::info(self::LOG_SKIPPED, ShopifyLogContext::receipt($receipt, ['account_id' => $this->accountId]));

            return;
        }

        if ($receipt->status === ShopifyWebhookReceipt::STATUS_QUEUED) {
            $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_PROCESSING);
        }

        try {
            $this->handleTopic($receipt);
        } catch (Throwable $e) {
            $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_FAILED, $e->getMessage());

            Log::error(self::LOG_FAILED, ShopifyLogContext::receipt($receipt, [
                'account_id' => $this->accountId,
                'exception' => $e::class,
            ]));

            throw $e; // fail loud: the worker records it, the recovery sweep can replay
        }

        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_PROCESSED);

        Log::info(self::LOG_HANDLED, ShopifyLogContext::receipt($receipt, ['account_id' => $this->accountId]));
    }

    /** The topic work. Runs with $this->accountId bound; the receipt is in `processing`. */
    abstract protected function handleTopic(ShopifyWebhookReceipt $receipt): void;
}
