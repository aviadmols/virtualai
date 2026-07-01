<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\MerchantSiteTenancy;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BindMerchantAccount — the tenant-binding floor for the merchant Filament panel.
 *
 * The merchant panel is SHOP-centric (Filament tenant = Site), but the ACCOUNT stays the
 * security boundary: this binds the Tenant (account) context for the WHOLE request so every
 * BelongsToAccount query is auto-scoped. Registered as PERSISTENT tenant middleware, so it runs
 * AFTER Filament resolves the shop tenant (needed for the super-admin branch) yet still wraps the
 * render, clearing in finally (TS-TENANCY-001).
 *
 * Two account sources, both read WITHOUT trusting the request body:
 *  1. Account owner  → their own account_id (from the AUTH user).
 *  2. Super-admin drill-in → the account of the active shop (Filament tenant Site), re-derived
 *     every request from the URL so it can never point at a different account. A super-admin has
 *     no account of their own; the shop they drilled into is the single source of truth.
 *
 * FAIL-CLOSED: no resolvable account → bind NOTHING, leaving the fail-closed global scope to
 * return an empty set (never another account's rows). No withoutGlobalScopes() — this only
 * NARROWS to one account.
 */
final class BindMerchantAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $this->resolveAccountId($request);

        if ($accountId === null) {
            return $next($request);
        }

        return Tenant::run($accountId, static fn (): Response => $next($request));
    }

    /**
     * The account to bind, read from the AUTH user / the active shop tenant ONLY — never from the
     * request/session/domain (TS-TENANCY-001).
     */
    private function resolveAccountId(Request $request): ?int
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $accountId = $user->getAttribute('account_id');

        if ($accountId !== null) {
            return (int) $accountId; // account owner — bind their own account
        }

        // Super-admin drill-in: derive the account from the active shop (Filament tenant), which
        // is the URL-resolved Site gated by User::canAccessTenant. Re-read every request. The
        // try/catch keeps it robust outside a panel/tenant context (fail closed → bind nothing).
        if ($user instanceof User && $user->isSuperAdmin()) {
            try {
                $tenant = Filament::getTenant();
            } catch (\Throwable) {
                $tenant = null;
            }

            if ($tenant instanceof Site) {
                return MerchantSiteTenancy::accountIdForSite($tenant);
            }
        }

        return null;
    }
}
