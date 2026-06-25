<?php

namespace App\Http\Widget;

use Illuminate\Support\Facades\DB;

/**
 * SiteRouter — resolve which ACCOUNT a public site_key belongs to, BEFORE any tenant is
 * bound. The same shape as the TS-CREDITS-004 purchase router: a widget request arrives
 * with no bound tenant, but Site is BelongsToAccount (fail-closed -> returns NOTHING when
 * unbound), so Site::where('site_key', …) cannot find it pre-bind.
 *
 * The audited, documented exception is a SINGLE routing lookup that returns ONLY the
 * integer account_id (a routing fact — never money / PII / secret / row data), keyed by
 * the globally-unique public site_key. The middleware then Tenant::run($accountId, …) and
 * re-reads the full Site through the NORMAL fail-closed global scope. This keeps the
 * cross-scope surface to one integer in one named class the isolation audit can reason
 * about — NOT withoutGlobalScopes(), and never an unscoped model hydrate.
 *
 * site_key is the PUBLIC key; the encrypted widget_secret is NEVER read here.
 */
final class SiteRouter
{
    // === CONSTANTS ===
    private const SITES_TABLE = 'sites';
    private const SITE_KEY_COLUMN = 'site_key';
    private const ACCOUNT_ID_COLUMN = 'account_id';

    /**
     * The account_id that owns this public site_key, or null if the key is unknown. Only
     * the integer leaves this method; nothing else is read off the row.
     */
    public function accountIdForSiteKey(string $siteKey): ?int
    {
        if ($siteKey === '') {
            return null; // never match an empty key (NULL-not-empty guard, see Site model)
        }

        $accountId = DB::table(self::SITES_TABLE)
            ->where(self::SITE_KEY_COLUMN, $siteKey)
            ->value(self::ACCOUNT_ID_COLUMN);

        return $accountId === null ? null : (int) $accountId;
    }
}
