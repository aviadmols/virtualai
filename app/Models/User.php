<?php

namespace App\Models;

use App\Domain\Tenancy\MerchantSiteTenancy;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * User — authentication.
 *
 * An account owner belongs to a tenant (account_id set); a platform
 * super-admin is global (account_id NULL + is_super_admin = true). User is on
 * the GlobalModels allow-list and is deliberately NOT BelongsToAccount: auth
 * resolves a user BEFORE a tenant is bound, and super-admins must be visible
 * across all accounts. Account-owner isolation is enforced explicitly at the
 * panel/query layer via the forAccount() query scope, not via the global
 * tenant scope.
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    // === CONSTANTS ===
    public const COLUMN_ACCOUNT_ID = 'account_id';
    private const PANEL_PLATFORM = 'platform';
    private const PANEL_MERCHANT = 'merchant';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'is_super_admin',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Constrain a user query to one account's owners. Used by the merchant
     * panel (Phase 8) to list account-scoped users; the real isolation tool
     * for this global model (TS-TENANCY-003).
     */
    public function scopeForAccount(Builder $query, Account|int $account): Builder
    {
        return $query->where(
            self::COLUMN_ACCOUNT_ID,
            $account instanceof Account ? $account->getKey() : $account,
        );
    }

    /** A platform super-admin is global (not account-scoped). */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Panel access gate (required by Filament in non-local environments).
     * Platform = super-admins only; Merchant = account owners, PLUS super-admins who drill into
     * a specific shop (they carry no account and land on a tenant URL; canAccessTenant + the
     * tenant menu's "Exit to platform" bound their session to that one shop).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            self::PANEL_PLATFORM => $this->isSuperAdmin(),
            self::PANEL_MERCHANT => $this->isSuperAdmin() || $this->account_id !== null,
            default => false,
        };
    }

    // === Filament SITE tenancy (the merchant "shop" switcher) ===

    /**
     * The shops (Site tenants) this user may switch between in the merchant panel — the security
     * boundary for the switcher. A merchant sees ONLY their account's sites (via the audited seam,
     * read through the normal account scope). A super-admin's list is empty: they reach a shop by
     * drill-in URL (gated by canAccessTenant), not the menu. account_id === null → empty (fail-closed).
     *
     * @return Collection<int, Site>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isSuperAdmin() || $this->account_id === null) {
            return collect();
        }

        return MerchantSiteTenancy::sitesForAccount((int) $this->account_id);
    }

    /** The landing shop for a bare /merchant visit (the merchant's first site). */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }

    /**
     * Ownership gate for a shop (Site) — THE authoritative tenant-access check. A merchant may
     * access only their own account's shops; a super-admin may drill into any shop. Reads the
     * AUTH user only, never the request. This is what makes the cross-account tenant RESOLUTION
     * in MerchantSiteTenancy::resolveBySlug safe.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Site) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->account_id !== null && (int) $tenant->account_id === (int) $this->account_id;
    }
}
