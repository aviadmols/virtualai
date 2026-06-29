<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BindMerchantAccount — the tenant-binding floor for the merchant Filament panel.
 *
 * For an authenticated account-owner, binds the Tenant context to the owner's
 * account for the WHOLE request lifecycle, so every BelongsToAccount query made
 * by a merchant resource is auto-scoped to that one account. Same shape as the
 * widget's ResolveWidgetSite: Tenant::run($accountId, fn () => $next($request))
 * — $next (controller + view render) runs INSIDE the bind, which clears in
 * finally so a worker/request never leaks one tenant into the next (TS-TENANCY-001).
 *
 * FAIL-CLOSED CONTRACT: this middleware resolves the account from the AUTH user
 * only (auth()->user()->account_id), never from the request. If there is no
 * authenticated user or no resolvable account_id, it binds NOTHING — leaving the
 * fail-closed global scope to return an empty set rather than another account's
 * rows. It runs AFTER Filament's Authenticate middleware, and the panel's
 * canAccessPanel gate already excludes super-admins (account_id === null), so the
 * no-account path is defence in depth, not the happy path.
 *
 * No withoutGlobalScopes() — this only NARROWS to one account, never widens.
 */
final class BindMerchantAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $this->resolveAccountId($request);

        // Fail closed: with no resolvable account, do NOT bind a tenant. The
        // BelongsToAccount global scope then returns nothing (sentinel), never
        // another account's data. Authenticate + canAccessPanel run first, so a
        // legitimate merchant request always has an account_id here.
        if ($accountId === null) {
            return $next($request);
        }

        // Bind for the WHOLE request lifecycle; $next runs inside the bind and the
        // context clears in finally (never ambient/stale — TS-TENANCY-001).
        return Tenant::run($accountId, static fn (): Response => $next($request));
    }

    /**
     * The authenticated account-owner's account_id, or null. Read from the auth
     * user ONLY — never from the request/session/domain (TS-TENANCY-001). A
     * super-admin (account_id === null) is excluded by canAccessPanel before this
     * runs; if one slips through, this returns null and the scope fails closed.
     */
    private function resolveAccountId(Request $request): ?int
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $accountId = $user->getAttribute('account_id');

        return $accountId === null ? null : (int) $accountId;
    }
}
