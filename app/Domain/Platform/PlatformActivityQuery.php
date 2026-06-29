<?php

namespace App\Domain\Platform;

use App\Models\ActivityEvent;
use App\Models\Concerns\AccountScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * PlatformActivityQuery — the AUDITED platform-admin cross-account read seam for the
 * activity timeline (the Super-Admin observability / logs view).
 *
 * ActivityEvent IS BelongsToAccount, so its fail-closed global scope returns NOTHING when
 * no tenant is bound — correct everywhere EXCEPT the control plane, which by design lists
 * EVERY account's events. That requires removing the AccountScope, and per CLAUDE.md the
 * ONLY place a global-scope bypass may live is an audited platform-admin service. This is
 * one such place — guarded by PlatformGuard (super-admin only), the same shape as
 * PlatformSiteQuery.
 *
 * READ-ONLY: ActivityRecorder is the only writer and the timeline is append-only. This
 * seam surfaces the owning account (account_id + the account relation) so the platform
 * log shows which account each event belongs to.
 */
final class PlatformActivityQuery
{
    /**
     * An ActivityEvent builder with the account global scope removed — across ALL tenants.
     * Use for the platform observability table / counts. THROWS for any non-super-admin.
     */
    public static function all(): Builder
    {
        PlatformGuard::assert();

        // A sanctioned global-scope bypass in product code (audited here + PlatformGuard).
        return ActivityEvent::query()->withoutGlobalScope(AccountScope::class);
    }

    /**
     * Eager-load the owning account for a cross-account event listing (the platform log
     * shows which account each event belongs to). Still guarded; still the one bypass.
     */
    public static function withAccount(): Builder
    {
        return self::all()->with('account');
    }

    /**
     * Cross-account events for ONE account (the platform account-detail activity tab).
     * Still routed through the audited seam + guard — never an inline withoutGlobalScope().
     */
    public static function forAccount(int $accountId): Builder
    {
        // AccountScope holds the column literal (a trait constant is unreadable via the
        // using-class name in PHP — TS-TENANCY-002).
        return self::all()->where(AccountScope::ACCOUNT_FOREIGN_KEY, $accountId);
    }
}
