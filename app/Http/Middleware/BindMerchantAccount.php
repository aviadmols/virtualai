<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\MerchantSiteTenancy;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BindMerchantAccount — the tenant-binding floor for the merchant Filament panel.
 *
 * The merchant panel is SHOP-centric (Filament tenant = Site), but the ACCOUNT stays the
 * security boundary: this binds the Tenant (account) context so every BelongsToAccount query is
 * auto-scoped. Registered as PERSISTENT tenant middleware, so it runs AFTER Filament resolves the
 * shop tenant (needed for the super-admin branch).
 *
 * The bind is REQUEST-LIFETIME, not a closure window. Filament runs every merchant Save / action /
 * "pick on page" as a Livewire "update" on a SEPARATE, TERMINAL persistent-middleware pipeline: the
 * tenant middleware's $next returns immediately (Livewire\Drawer\Utils::applyMiddleware ends in an
 * empty response), so a $next-scoped bind would clear BEFORE the component method executes — leaving
 * every account-scoped read/write fail-closed on the write path (Save no-ops, {site} bindings 404).
 * Binding for the whole request (Tenant::bindForRequest, mirroring Filament::setTenant's lifetime)
 * and clearing on termination fixes the write path for owners AND super-admin drill-in alike.
 *
 * Two account sources, both read WITHOUT trusting the request body:
 *  1. Account owner  → their own account_id (from the AUTH user).
 *  2. Super-admin drill-in → the account of the active shop (Filament tenant Site), re-derived
 *     every request from the URL so it can never point at a different account.
 *
 * FAIL-CLOSED: no resolvable account → clear + bind NOTHING, leaving the fail-closed global scope to
 * return an empty set (never another account's rows). No withoutGlobalScopes() — this only NARROWS
 * to one account. Leak-safe (TS-TENANCY-001): bindForRequest clears any stale binding FIRST, and a
 * terminating callback clears at request end, so no request can bleed its account into the next.
 */
final class BindMerchantAccount
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $this->resolveAccountId($request);

        if ($accountId === null) {
            // No resolvable account — clear any stale binding and bind nothing (fail closed).
            Tenant::clear();

            return $next($request);
        }

        // Bind for the WHOLE request so the tenant survives to the Livewire-update component
        // method (the terminal persistent-middleware pipeline clears a $next-scoped bind too early).
        Tenant::bindForRequest($accountId);

        // Belt-and-suspenders leak guard: clear when this request terminates, in addition to
        // bindForRequest's clear-first-on-bind at the start of the NEXT request.
        $this->app->terminating(static fn () => Tenant::clear());

        return $next($request);
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
