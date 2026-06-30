<?php

namespace App\Domain\Platform;

use App\Models\Concerns\AccountScope;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * PlatformProductQuery — the AUDITED platform-admin cross-account read seam for Product
 * (the Super-Admin scan review/confirm + setup-status surfaces).
 *
 * Product (and ProductVariant) IS BelongsToAccount, so its fail-closed global scope returns
 * NOTHING when no tenant is bound — correct everywhere EXCEPT the control plane, where the
 * super-admin reviews a site's scanned products WITHOUT a bound tenant. That requires
 * removing the AccountScope, and per CLAUDE.md the ONLY place a global-scope bypass may live
 * is an audited platform-admin service. This is one such place — the same shape as
 * PlatformSiteQuery / PlatformActivityQuery, guarded by PlatformGuard (super-admin only).
 *
 * GUARDED: every entry point asserts a confirmed super-admin and throws
 * PlatformAccessRequiredException otherwise, so a merchant (or any non-super-admin) can never
 * reach the cross-account builder. READ-ONLY: the CONFIRM write goes through
 * ConfirmScanAction, which binds the product's own account via Tenant::run — a tenant
 * BINDING, never a scope bypass. There is NO inline withoutGlobalScopes() outside this seam.
 */
final class PlatformProductQuery
{
    /**
     * A Product builder with the account global scope removed — across ALL tenants.
     * THROWS for any non-super-admin. The base of every other method here.
     */
    public static function all(): Builder
    {
        PlatformGuard::assert();

        // A sanctioned global-scope bypass in product code (audited here + PlatformGuard).
        return Product::query()->withoutGlobalScope(AccountScope::class);
    }

    /**
     * The scanned products for ONE site (the platform per-site review list), variants
     * eager-loaded, newest first. Still routed through the guarded seam.
     */
    public static function forSite(int $siteId): Builder
    {
        return self::all()
            ->where('site_id', $siteId)
            ->with(self::variantsWithoutScope())
            ->orderByDesc('created_at');
    }

    /**
     * Load one product (cross-account) with its variants for the read-only review panel.
     * Still guarded; still the one bypass. Returns null when not found.
     */
    public static function findWithVariants(int $productId): ?Product
    {
        return self::all()->with(self::variantsWithoutScope())->find($productId);
    }

    /**
     * The variants eager-load with the account scope removed too. ProductVariant IS
     * BelongsToAccount, so the eager-load relation re-applies AccountScope and would
     * return NOTHING with no bound tenant — the same sanctioned platform bypass extends to
     * the child relation so the super-admin sees a product's variants cross-account.
     *
     * @return array<string,\Closure>
     */
    private static function variantsWithoutScope(): array
    {
        return [
            'variants' => static fn ($relation) => $relation->withoutGlobalScope(AccountScope::class),
        ];
    }

    /**
     * Count a site's products in a given scan status (e.g. CONFIRMED) — the setup-status
     * "has a confirmed product" check. Still guarded; integer-only result.
     */
    public static function countForSiteWithStatus(int $siteId, string $status): int
    {
        return self::all()
            ->where('site_id', $siteId)
            ->where('status', $status)
            ->count();
    }
}
