<?php

namespace App\Domain\Tenancy;

use App\Models\Concerns\AccountScope;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * MerchantSiteTenancy — the audited seam behind the merchant panel's Filament SITE tenancy.
 *
 * The merchant "shop" is a Site (the Filament tenant), but the ACCOUNT stays the security
 * boundary (BelongsToAccount + BindMerchantAccount). Two entry points:
 *
 *  - sitesForAccount(): the shops in the tenant switcher — read through the NORMAL account
 *    scope by binding the account (Tenant::run), so there is NO global-scope bypass. Safe by
 *    construction: it can only ever return the caller's own account's sites.
 *
 *  - resolveBySlug(): tenant RESOLUTION from the URL slug. AccountScope fails closed with no
 *    bound tenant (and a super-admin has no account to bind), so this is the ONE place a Site
 *    is read across accounts. It is SAFE because User::canAccessTenant is applied by Filament
 *    IMMEDIATELY after as the authoritative ownership gate (a merchant only passes their own
 *    account's shops; a super-admin passes any). This is an audited bypass alongside
 *    PlatformSiteQuery — it widens NO list view, only the single-tenant lookup that
 *    canAccessTenant then authorizes.
 */
final class MerchantSiteTenancy
{
    /** The sites owned by $accountId, read through the normal account scope (no bypass). */
    public static function sitesForAccount(int $accountId): Collection
    {
        return Tenant::run($accountId, static fn (): Collection => Site::query()
            ->orderBy('name')
            ->get());
    }

    /**
     * Resolve a Site by its tenant slug ACROSS accounts, for Filament tenant routing. The
     * ownership decision is made by User::canAccessTenant right after — never here.
     */
    public static function resolveBySlug(string $slug): ?Site
    {
        return Site::query()
            ->withoutGlobalScope(AccountScope::class)
            ->where('slug', $slug)
            ->first();
    }

    /** The owning account id of an already-resolved tenant Site (super-admin drill-in bind). */
    public static function accountIdForSite(Site $site): int
    {
        return (int) $site->account_id;
    }
}
