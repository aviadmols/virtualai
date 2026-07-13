<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\ShopifyLogContext;
use App\Models\ShopifyWebhookReceipt;
use Illuminate\Support\Facades\Log;

/**
 * AcknowledgeGdprWebhookJob — the day-one handler for the three MANDATORY compliance
 * topics (customers/data_request, customers/redact, shop/redact).
 *
 * Shopify requires a listed app to RECEIVE and answer these from the first install
 * (docs/shopify/DECISIONS.md §4). Phase 7 wires them to the real erasure machinery
 * (retention/privacy). Until then the delivery is DURABLE — verified, receipted, and
 * marked processed — so nothing is lost and the request is auditable/replayable: the
 * receipt row keeps the payload, and re-pointing this topic at the real eraser in
 * Phase 7 needs no change to the intake.
 *
 * It deliberately performs NO erasure: silently pretending to erase would be worse than
 * an explicit, logged, replayable acknowledgement.
 */
final class AcknowledgeGdprWebhookJob extends HandleShopifyWebhookJob
{
    // === CONSTANTS ===
    private const LOG_ACKNOWLEDGED = 'shopify.gdpr.acknowledged';

    protected function handleTopic(ShopifyWebhookReceipt $receipt): void
    {
        Log::info(self::LOG_ACKNOWLEDGED, ShopifyLogContext::receipt($receipt, [
            'account_id' => $this->accountId,
        ]));
    }
}
