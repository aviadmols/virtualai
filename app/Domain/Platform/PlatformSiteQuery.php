<?php

namespace App\Domain\Platform;

use App\Models\Concerns\AccountScope;
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
     * shows which account each site belongs to). Still guarded; still the one bypass.
     */
    public static function withAccount(): Builder
    {
        return self::all()->with('account');
    }
}
