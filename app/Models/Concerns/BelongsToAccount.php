<?php

namespace App\Models\Concerns;

use App\Exceptions\CrossTenantWriteException;
use App\Models\Account;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Marks a model as account-owned (tenant-scoped).
 *
 * Adds two behaviours, both driven by the bound Tenant:
 *  1. A global scope that filters every query to the bound account_id.
 *  2. A creating hook that auto-fills account_id from the bound tenant.
 *
 * FAIL-CLOSED CONTRACT: when NO tenant is bound, the global scope constrains
 * account_id to an impossible value so the query returns NOTHING. A forgotten
 * Tenant::run() (or a forgotten where()) can therefore never leak another
 * account's rows — it returns an empty set instead. Isolation is a release
 * blocker, so the unsafe default (return everything) is never possible.
 *
 * No withoutGlobalScopes() is permitted in product code; only a future audited
 * platform-admin service may bypass the scope.
 */
trait BelongsToAccount
{
    // === CONSTANTS ===
    public const ACCOUNT_FOREIGN_KEY = 'account_id';

    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (Model $model): void {
            $explicit = $model->getAttribute(self::ACCOUNT_FOREIGN_KEY);

            if ($explicit !== null) {
                // A bound tenant must never stamp a row for a DIFFERENT account
                // (a cross-tenant write). Fail loud rather than persist it.
                if (Tenant::check() && (int) $explicit !== Tenant::id()) {
                    throw CrossTenantWriteException::for(static::class, Tenant::id(), (int) $explicit);
                }

                return; // explicit account_id matches the tenant, or no tenant is bound
            }

            if (! Tenant::check()) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s without a bound tenant: account_id is required and no Tenant::run() is active.',
                    static::class,
                ));
            }

            $model->setAttribute(self::ACCOUNT_FOREIGN_KEY, Tenant::id());
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, self::ACCOUNT_FOREIGN_KEY);
    }
}

/**
 * The global scope that enforces the bound account_id on every query.
 * Fails closed (sentinel = no rows) when no tenant is bound.
 */
final class AccountScope implements Scope
{
    // === CONSTANTS ===
    // The tenant foreign key. Held here too because a trait constant cannot be
    // read via the trait name from another class (PHP language limit); this is
    // the authoritative column the scope filters on.
    public const ACCOUNT_FOREIGN_KEY = 'account_id';

    /** An account id that can never exist, used to fail closed when unbound. */
    private const NO_TENANT_SENTINEL = 0;

    public function apply(Builder $builder, Model $model): void
    {
        $accountId = Tenant::check()
            ? Tenant::current()
            : self::NO_TENANT_SENTINEL;

        $builder->where(
            $model->qualifyColumn(self::ACCOUNT_FOREIGN_KEY),
            $accountId,
        );
    }
}
