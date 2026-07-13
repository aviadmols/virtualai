<?php

namespace App\Domain\Shopify;

use App\Models\ShopifyWebhookReceipt;

/**
 * ShopifyLogContext — the ONE structured-context builder every Shopify log line uses,
 * so a failure spanning Shopify -> receipts -> queue -> handler is traceable by
 * correlation_id / shop_domain / webhook_id without grepping free text.
 */
final class ShopifyLogContext
{
    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public static function receipt(ShopifyWebhookReceipt $receipt, array $extra = []): array
    {
        return [
            'correlation_id' => $receipt->correlation_id,
            'webhook_id' => $receipt->webhook_id,
            'topic' => $receipt->topic,
            'shop_domain' => $receipt->shop_domain,
            'receipt_id' => $receipt->getKey(),
            'attempts' => $receipt->attempts,
        ] + $extra;
    }
}
