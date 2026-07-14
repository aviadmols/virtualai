<?php

namespace App\Domain\Platform;

use App\Models\Concerns\AccountScope;
use App\Models\ShopifyConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * PlatformSiteQuery — the AUDITED, SOLE platform-admin cross-account read seam for Site.
 *
 * Site IS BelongsToAccount, so its fail-closed global scope returns NOTHING when no
 * tenant is bound — correct everywhere EXCEPT the Super-Admin control plane, which by
 * design lists EVERY account's sites. That requires removing the AccountScope, and per
 * CLAUDE.md the ONLY place a global-scope bypass may live is "an audited platform-admin
 * service". This is that one place — and the only withoutGlobalScopes(AccountScope::class)
 * in product code.
 *
 * GUARDED: every entry point asserts a confirmed super-admin (User::isSuperAdmin()) and
 * throws PlatformAccessRequiredException otherwise. The bypass is therefore UNUSABLE
 * outside the platform context — a merchant request (or any non-super-admin caller) can
 * never reach the cross-account builder. The platform Filament Site resource calls
 * PlatformSiteQuery::all() for its table query.
 *
 * Account itself is the tenant root (NOT BelongsToAccount) and already reads globally, so
 * it needs no seam; only Site (and other BelongsToAccount control-plane reads) does.
 */
final class PlatformSiteQuery
{
    /**
     * A Site builder with the account global scope removed — across ALL tenants.
     * Use for the platform Site resource table / counts. THROWS for any non-super-admin.
     */
    public static function all(): Builder
    {
        PlatformGuard::assert();

        // A sanctioned global-scope bypass in product code (audited here + PlatformGuard).
        return Site::query()->withoutGlobalScope(AccountScope::class);
    }

    /**
     * Eager-load the owning account for a cross-account site listing (the platform table
     * shows which account each site belongs to), and SELECT the Shopify connection's state
     * as plain columns. Still guarded; still the one bypass.
     *
     * WHY SUBQUERIES AND NOT ->with('shopifyConnection'). ShopifyConnection is itself
     * BelongsToAccount. Two things then go wrong, and the second is the dangerous one:
     *
     *   1. A plain eager load runs the child query under the fail-closed AccountScope with
     *      NO tenant bound (the platform panel has none), so it resolves to null for every
     *      site.
     *   2. Even WITH the scope stripped on the eager load, a lazy read of the relation on a
     *      record Filament re-hydrated without the eager load silently re-applies the scope
     *      and returns null again — verified in a probe. The listing would then report "Not
     *      connected" for stores that ARE connected: a silent lie, which is worse than an
     *      error, because nobody goes looking for it.
     *
     * A correlated subquery cannot be lied to by either mechanism: the answer is computed
     * in SQL, inside this audited seam, and arrives as an ordinary column. It is also
     * sortable and filterable for free.
     */
    public static function withAccount(): Builder
    {
        $connection = static fn (string $column): Builder => ShopifyConnection::query()
            ->withoutGlobalScope(AccountScope::class)
            ->select($column)
            ->whereColumn('shopify_connections.site_id', 'sites.id')
            ->limit(1);

        return self::all()
            ->with('account')
            ->select('sites.*')
            ->addSelect([
                'shopify_status' => $connection('status'),
                'shopify_needs_reauth' => $connection('needs_reauth'),
                'shopify_shop_domain' => $connection('shop_domain'),
            ]);
    }
}
