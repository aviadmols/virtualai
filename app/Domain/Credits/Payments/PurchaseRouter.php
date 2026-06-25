<?php

namespace App\Domain\Credits\Payments;

use Illuminate\Support\Facades\DB;

/**
 * PurchaseRouter — the ONE place a webhook (which arrives with NO bound tenant) maps a
 * provider_ref to the account that owns it, so the reconciler can bind that tenant and
 * read the purchase row through the NORMAL global scope.
 *
 * AUDIT NOTE (tenant-isolation): this is a deliberate, documented exception that does
 * NOT leak data. It is account-ROUTING, not a data read:
 *  - it returns ONLY the integer account_id (a routing fact), never money/PII/row data;
 *  - it is keyed by the GLOBALLY-UNIQUE (provider, provider_ref) the provider echoes
 *    back, which our own initiate() persisted — so it can only resolve a ref we minted;
 *  - it uses a raw query-builder count-equivalent on a single column BECAUSE the Eloquent
 *    global scope fails closed with no tenant (the whole point). It is NOT
 *    withoutGlobalScopes() on a model, and it never hydrates a tenant model unscoped.
 * Once the account is known, ALL subsequent reads/writes go through Tenant::run() + the
 * fail-closed global scope. This keeps the cross-account read surface to a single
 * integer, in one named class the isolation audit can reason about.
 */
final class PurchaseRouter
{
    // === CONSTANTS ===
    private const TABLE = 'credit_purchases';
    private const COLUMN_PROVIDER = 'provider';
    private const COLUMN_PROVIDER_REF = 'provider_ref';
    private const COLUMN_ACCOUNT_ID = 'account_id';

    /**
     * The account id that owns a (provider, provider_ref), or null if unknown. Returns
     * an INTEGER ONLY — never row data. The caller binds this tenant before any read.
     */
    public function accountIdForRef(string $provider, string $providerRef): ?int
    {
        $accountId = DB::table(self::TABLE)
            ->where(self::COLUMN_PROVIDER, $provider)
            ->where(self::COLUMN_PROVIDER_REF, $providerRef)
            ->value(self::COLUMN_ACCOUNT_ID);

        return $accountId !== null ? (int) $accountId : null;
    }
}
