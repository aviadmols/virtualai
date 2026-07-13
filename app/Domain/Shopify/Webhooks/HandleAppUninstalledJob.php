<?php

namespace App\Domain\Shopify\Webhooks;

use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use Illuminate\Support\Facades\Log;

/**
 * HandleAppUninstalledJob — the `app/uninstalled` topic handler.
 *
 * Shopify fires this once the merchant removes the app; the offline token is DEAD from
 * that moment. The connection moves installed -> uninstalled through the guarded
 * transition, which WIPES the encrypted credentials and writes the timeline event. The
 * row itself survives (shop_domain stays the routing key), so a later re-install
 * RE-ACTIVATES it instead of duplicating.
 *
 * Idempotent: an already-uninstalled connection is a no-op (a replayed delivery, or the
 * merchant having disconnected from our panel first). The receipt state machine is
 * handled by the base class.
 */
final class HandleAppUninstalledJob extends HandleShopifyWebhookJob
{
    // === CONSTANTS ===
    private const LOG_UNINSTALLED = 'shopify.app_uninstalled';

    private const LOG_UNKNOWN = 'shopify.app_uninstalled.unknown_connection';

    private const DETAIL_SOURCE = 'source';

    private const SOURCE_WEBHOOK = 'app_uninstalled_webhook';

    protected function handleTopic(ShopifyWebhookReceipt $receipt): void
    {
        // Tenant-bound read (BelongsToAccount): only THIS account's connection resolves.
        $connection = ShopifyConnection::query()
            ->where('shop_domain', (string) $receipt->shop_domain)
            ->first();

        if ($connection === null) {
            Log::warning(self::LOG_UNKNOWN, [
                'correlation_id' => $receipt->correlation_id,
                'shop_domain' => $receipt->shop_domain,
                'account_id' => $this->accountId,
            ]);

            return; // nothing to uninstall; the receipt still completes (no retry storm)
        }

        if (! $connection->isInstalled()) {
            return; // already uninstalled — idempotent replay
        }

        // Guarded transition: wipes credentials, stamps uninstalled_at, writes the event.
        $connection->transitionTo(ShopifyConnection::STATUS_UNINSTALLED, [
            self::DETAIL_SOURCE => self::SOURCE_WEBHOOK,
            'correlation_id' => $receipt->correlation_id,
        ]);

        Log::info(self::LOG_UNINSTALLED, [
            'correlation_id' => $receipt->correlation_id,
            'shop_domain' => $connection->shop_domain,
            'account_id' => $this->accountId,
            'site_id' => (int) $connection->site_id,
        ]);
    }
}
