<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\Products\ShopifyGid;
use App\Domain\Shopify\Products\ShopifyProductImporter;
use App\Domain\Shopify\Products\ShopifyProductNotFoundException;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * HandleProductUpdateJob — the `products/update` topic handler.
 *
 * Shopify's push payload is a REST-shaped snapshot, NOT the Admin GraphQL shape our
 * mapper reads — and it can lag / omit fields. So the webhook is treated as a SIGNAL,
 * not as data: we take only the product id from it and RE-READ the product through the
 * Admin API (the single source of truth), then persist through the shared writer.
 *
 * Refresh-confirmed: PersistProduct updates the DATA of a CONFIRMED product and never
 * its status — a background webhook may not undo (or fake) a merchant's confirm.
 *
 * A product the API no longer has (deleted between the push and our read) is ARCHIVED,
 * never deleted — past generations reference it. A product the store UNPUBLISHED (status
 * DRAFT/ARCHIVED) is likewise not re-activated by this refresh: the re-read carries the
 * store's status, and the writer honours it — otherwise every save of an unpublished
 * product would flap it back into the widget the catalog walk just archived it from.
 */
final class HandleProductUpdateJob extends HandleShopifyWebhookJob
{
    // === CONSTANTS ===
    // The payload keys Shopify sends on products/update (REST shape).
    private const PAYLOAD_ID = 'id';

    private const LOG_NO_ID = 'shopify.products_update.no_id';

    private const LOG_NO_SITE = 'shopify.products_update.no_site';

    private const LOG_MISSING = 'shopify.products_update.not_found';

    private const LOG_DONE = 'shopify.products_update.persisted';

    protected function handleTopic(ShopifyWebhookReceipt $receipt): void
    {
        $gid = $this->productGid($receipt);

        if ($gid === null) {
            Log::warning(self::LOG_NO_ID, ['receipt_id' => $receipt->getKey(), 'account_id' => $this->accountId]);

            return; // nothing actionable; the receipt still completes (no retry storm)
        }

        $site = $this->site($receipt);

        if ($site === null) {
            return;
        }

        $importer = app(ShopifyProductImporter::class);

        try {
            // A transport / throttle / auth failure is a TYPED ShopifyApiException and is
            // deliberately NOT caught: the base class fails the receipt with the reason and
            // the recovery sweep can replay it. Only a GONE product is terminal here.
            $result = $importer->importOne($site, $gid);
        } catch (ShopifyProductNotFoundException) {
            Log::info(self::LOG_MISSING, ['gid' => $gid, 'account_id' => $this->accountId]);

            $importer->archiveByGid($site, $gid);

            return;
        }

        Log::info(self::LOG_DONE, [
            'correlation_id' => $receipt->correlation_id,
            'account_id' => $this->accountId,
            'site_id' => (int) $site->getKey(),
            'product_id' => $result->product->getKey(),
            'created' => $result->created,
            'status_preserved' => $result->statusPreserved,
            // The store's own status decides this — an unpublished product is NOT re-activated.
            'is_active' => (bool) $result->product->is_active,
        ]);
    }

    /** The product GID from the push payload's bare numeric id. */
    private function productGid(ShopifyWebhookReceipt $receipt): ?string
    {
        $id = ($receipt->payload ?? [])[self::PAYLOAD_ID] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return ShopifyGid::for(ShopifyGid::TYPE_PRODUCT, is_scalar($id) ? (string) $id : '');
    }

    /**
     * The Site this shop's webhook belongs to, read through the tenant-scoped connection
     * (BelongsToAccount — a handler bound to the wrong account resolves NOTHING).
     */
    private function site(ShopifyWebhookReceipt $receipt): ?Site
    {
        $connection = ShopifyConnection::query()
            ->where('shop_domain', (string) $receipt->shop_domain)
            ->first();

        if ($connection === null || ! $connection->isInstalled()) {
            Log::warning(self::LOG_NO_SITE, [
                'correlation_id' => $receipt->correlation_id,
                'shop_domain' => $receipt->shop_domain,
                'account_id' => $this->accountId,
            ]);

            return null;
        }

        return $connection->site;
    }
}
