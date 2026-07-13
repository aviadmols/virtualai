<?php

namespace App\Http\Shopify;

use Illuminate\Support\Facades\DB;

/**
 * ShopifyShopRouter — resolve which ACCOUNT a Shopify shop domain belongs to, BEFORE
 * any tenant is bound. The exact SiteRouter/PurchaseRouter shape: a Shopify webhook
 * arrives with no bound tenant, but ShopifyConnection is BelongsToAccount (fail-closed
 * -> returns NOTHING when unbound), so ShopifyConnection::where('shop_domain', …)
 * cannot find it pre-bind.
 *
 * The audited, documented exception is a SINGLE routing lookup that returns ONLY the
 * integer account_id (a routing fact — never a token / row data), keyed by the
 * globally-unique shop_domain. The caller then Tenant::run($accountId, …) and re-reads
 * the connection through the NORMAL fail-closed global scope. NOT withoutGlobalScopes(),
 * and never an unscoped model hydrate. The encrypted credentials are NEVER read here.
 */
final class ShopifyShopRouter
{
    // === CONSTANTS ===
    private const CONNECTIONS_TABLE = 'shopify_connections';

    private const SHOP_DOMAIN_COLUMN = 'shop_domain';

    private const ACCOUNT_ID_COLUMN = 'account_id';

    /**
     * The account_id that owns this shop domain, or null if the shop is unknown. Only
     * the integer leaves this method; nothing else is read off the row.
     */
    public function accountIdForShopDomain(string $shopDomain): ?int
    {
        if ($shopDomain === '') {
            return null;
        }

        $accountId = DB::table(self::CONNECTIONS_TABLE)
            ->where(self::SHOP_DOMAIN_COLUMN, $shopDomain)
            ->value(self::ACCOUNT_ID_COLUMN);

        return $accountId === null ? null : (int) $accountId;
    }
}
