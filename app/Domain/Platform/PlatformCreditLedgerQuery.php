<?php

namespace App\Domain\Platform;

use App\Models\Concerns\AccountScope;
use App\Models\CreditLedger;
use Illuminate\Database\Eloquent\Builder;

/**
 * PlatformCreditLedgerQuery — the AUDITED platform-admin cross-account read seam for the
 * credit ledger (the Super-Admin "platform credits" view).
 *
 * CreditLedger IS BelongsToAccount, so its fail-closed global scope returns NOTHING when
 * no tenant is bound — correct everywhere EXCEPT the control plane, which by design lists
 * EVERY account's money rows. That requires removing the AccountScope, and per CLAUDE.md
 * the ONLY place a global-scope bypass may live is an audited platform-admin service.
 * This is one such place — guarded by PlatformGuard (super-admin only), the same shape as
 * PlatformSiteQuery.
 *
 * READ-ONLY: the ledger is append-only and CreditLedgerService is its only writer. This
 * seam never mutates a row; it surfaces the owning account (account_id + the account
 * relation) on each ledger row so the platform table shows which account each money row
 * belongs to.
 */
final class PlatformCreditLedgerQuery
{
    /**
     * A CreditLedger builder with the account global scope removed — across ALL tenants.
     * Use for the platform credits table / counts. THROWS for any non-super-admin.
     */
    public static function all(): Builder
    {
        PlatformGuard::assert();

        // A sanctioned global-scope bypass in product code (audited here + PlatformGuard).
        return CreditLedger::query()->withoutGlobalScope(AccountScope::class);
    }

    /**
     * Eager-load the owning account for a cross-account ledger listing (the platform table
     * shows which account each money row belongs to). Still guarded; still the one bypass.
     */
    public static function withAccount(): Builder
    {
        return self::all()->with('account');
    }

    /**
     * Cross-account ledger rows for ONE account (the platform account-detail credits tab).
     * Still routed through the audited seam + guard — never an inline withoutGlobalScope().
     */
    public static function forAccount(int $accountId): Builder
    {
        // AccountScope holds the column literal (a trait constant is unreadable via the
        // using-class name in PHP — TS-TENANCY-002).
        return self::all()->where(AccountScope::ACCOUNT_FOREIGN_KEY, $accountId);
    }
}
