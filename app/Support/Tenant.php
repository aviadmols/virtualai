<?php

namespace App\Support;

use App\Models\Account;
use Closure;
use RuntimeException;

/**
 * The tenant context — the single source of the currently-bound Account.
 *
 * The bound account drives the BelongsToAccount global scope and the
 * account_id auto-fill on every tenant-owned model. Nothing else may set the
 * context; only run() binds and clears it (see TS-TENANCY-001).
 */
final class Tenant
{
    // === CONSTANTS ===
    private const NO_TENANT_MESSAGE = 'No tenant bound: Tenant::id() called outside a Tenant::run() scope.';

    /** The currently-bound account id, or null when no tenant is bound. */
    private static ?int $accountId = null;

    /**
     * Run a callback with $account bound as the active tenant.
     *
     * Binds in try, ALWAYS clears in finally — even on exception — so a worker
     * can never leak one job's tenant into the next (TS-TENANCY-001). Restores
     * any previously-bound tenant on exit so nested scopes are safe.
     *
     * @template TReturn
     * @param  Closure():TReturn  $callback
     * @return TReturn
     */
    public static function run(Account|int $account, Closure $callback): mixed
    {
        $previous = self::$accountId;
        self::set($account);

        try {
            return $callback();
        } finally {
            self::$accountId = $previous;
        }
    }

    /** The bound account id, or null if no tenant is bound. */
    public static function current(): ?int
    {
        return self::$accountId;
    }

    /** The bound account id; throws if no tenant is bound (fail loud). */
    public static function id(): int
    {
        if (self::$accountId === null) {
            throw new RuntimeException(self::NO_TENANT_MESSAGE);
        }

        return self::$accountId;
    }

    /** True when a tenant is currently bound. */
    public static function check(): bool
    {
        return self::$accountId !== null;
    }

    /**
     * Bind an account as the active tenant. Private to run(): callers must use
     * run() so the context is always cleared in finally.
     */
    private static function set(Account|int $account): void
    {
        self::$accountId = $account instanceof Account ? $account->getKey() : $account;
    }

    /**
     * Bind an account for the REST OF THE HTTP REQUEST (not a closure window).
     *
     * The merchant panel needs this because Filament runs a Livewire "update"
     * (every Save / action / pick) on a SEPARATE terminal middleware pipeline: the
     * tenant middleware's $next returns immediately, so a run()-scoped bind would
     * clear before the component method executes. This binds for the whole request
     * instead, mirroring Filament::setTenant's lifetime.
     *
     * Leak-safe by construction: it FIRST clears any stale binding (so a prior
     * request can never bleed in), then re-binds. BindMerchantAccount calls this at
     * the very start of every merchant request (GET and Livewire update), before any
     * component runs, so the request always starts from a clean, correct account.
     */
    public static function bindForRequest(Account|int $account): void
    {
        self::$accountId = null;
        self::set($account);
    }

    /**
     * Force-clear the tenant context. Test/CLI escape hatch only — product code
     * relies on run()'s finally to clear, never a manual clear.
     */
    public static function clear(): void
    {
        self::$accountId = null;
    }
}
