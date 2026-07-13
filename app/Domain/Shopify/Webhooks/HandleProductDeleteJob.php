<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\Products\ShopifyGid;
use App\Domain\Shopify\Products\ShopifyProductImporter;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * HandleProductDeleteJob — the `products/delete` topic handler.
 *
 * The product is gone from the store, so it stops being offered for NEW try-ons. It is
 * ARCHIVED (is_active=false + archived_at), NEVER deleted: `generations.product_id` and
 * every gallery entry point at this row, and the merchant paid credits for that history.
 * Deleting would orphan the FKs and erase paid-for work.
 *
 * Idempotent: a replayed delete for an already-archived product changes nothing.
 */
final class HandleProductDeleteJob extends HandleShopifyWebhookJob
{
    // === CONSTANTS ===
    private const PAYLOAD_ID = 'id';

    private const LOG_NO_ID = 'shopify.products_delete.no_id';

    private const LOG_NO_SITE = 'shopify.products_delete.no_site';

    private const LOG_DONE = 'shopify.products_delete.archived';

    protected function handleTopic(ShopifyWebhookReceipt $receipt): void
    {
        $id = ($receipt->payload ?? [])[self::PAYLOAD_ID] ?? null;

        if ($id === null || $id === '') {
            Log::warning(self::LOG_NO_ID, ['receipt_id' => $receipt->getKey(), 'account_id' => $this->accountId]);

            return;
        }

        $connection = ShopifyConnection::query()
            ->where('shop_domain', (string) $receipt->shop_domain)
            ->first();

        if ($connection === null) {
            Log::warning(self::LOG_NO_SITE, [
                'correlation_id' => $receipt->correlation_id,
                'shop_domain' => $receipt->shop_domain,
                'account_id' => $this->accountId,
            ]);

            return;
        }

        /** @var Site $site */
        $site = $connection->site;
        $gid = ShopifyGid::for(ShopifyGid::TYPE_PRODUCT, is_scalar($id) ? (string) $id : '');

        $product = app(ShopifyProductImporter::class)->archiveByGid($site, $gid);

        Log::info(self::LOG_DONE, [
            'correlation_id' => $receipt->correlation_id,
            'account_id' => $this->accountId,
            'site_id' => (int) $site->getKey(),
            'gid' => $gid,
            'product_id' => $product?->getKey(),
        ]);
    }
}
