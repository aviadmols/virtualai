<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\ShopifyLogContext;
use App\Models\ShopifyWebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * RecoverStuckShopifyWebhooksJob — the scheduled sweep that makes the receipt row,
 * not the queue, the source of truth for webhook delivery.
 *
 * Closes the loss window where Shopify already got a 200 but the dispatched handler
 * job never ran (dispatch raced a deploy, Redis hiccup, worker OOM): any receipt
 * stuck in received/queued past the threshold is re-dispatched through the SAME
 * ShopifyWebhookDispatcher the intake uses — bounded by max_attempts, then failed
 * loudly for the platform panel. Also prunes old receipts' payload bodies after the
 * retention window (the routing/audit columns stay).
 *
 * Platform housekeeping over PRE-BIND rows — deliberately NOT a TenantAwareJob; the
 * tenant is bound by the handler each receipt dispatches to.
 */
final class RecoverStuckShopifyWebhooksJob implements ShouldQueue
{
    use Queueable;

    // === CONSTANTS ===
    private const CFG_STUCK_AFTER_MINUTES = 'shopify.receipts.stuck_after_minutes';

    private const CFG_MAX_ATTEMPTS = 'shopify.receipts.max_attempts';

    private const CFG_RETENTION_DAYS = 'shopify.receipts.retention_days';

    private const CHUNK = 100;

    private const LOG_RECOVERED = 'shopify.webhook.recovered';

    private const LOG_EXHAUSTED = 'shopify.webhook.attempts_exhausted';

    private const ERROR_EXHAUSTED = 'Exceeded max dispatch attempts (recovery sweep).';

    public function handle(ShopifyWebhookDispatcher $dispatcher): void
    {
        $this->redispatchStuck($dispatcher);
        $this->prunePayloads();
    }

    private function redispatchStuck(ShopifyWebhookDispatcher $dispatcher): void
    {
        $threshold = now()->subMinutes((int) config(self::CFG_STUCK_AFTER_MINUTES));
        $maxAttempts = (int) config(self::CFG_MAX_ATTEMPTS);

        ShopifyWebhookReceipt::query()
            ->whereIn('status', ShopifyWebhookReceipt::STATUSES_STUCK)
            ->where('updated_at', '<', $threshold)
            ->chunkById(self::CHUNK, function ($receipts) use ($dispatcher, $maxAttempts): void {
                foreach ($receipts as $receipt) {
                    if ((int) $receipt->attempts >= $maxAttempts) {
                        $receipt->transitionTo(ShopifyWebhookReceipt::STATUS_FAILED, self::ERROR_EXHAUSTED);
                        Log::warning(self::LOG_EXHAUSTED, ShopifyLogContext::receipt($receipt));

                        continue;
                    }

                    if ($dispatcher->dispatch($receipt)) {
                        Log::info(self::LOG_RECOVERED, ShopifyLogContext::receipt($receipt));
                    }
                }
            });
    }

    /** Terminal receipts keep their routing/audit columns; the body is pruned. */
    private function prunePayloads(): void
    {
        ShopifyWebhookReceipt::query()
            ->whereIn('status', [ShopifyWebhookReceipt::STATUS_PROCESSED, ShopifyWebhookReceipt::STATUS_FAILED])
            ->whereNotNull('payload')
            ->where('updated_at', '<', now()->subDays((int) config(self::CFG_RETENTION_DAYS)))
            ->update(['payload' => null]);
    }
}
