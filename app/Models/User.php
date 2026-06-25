<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    // === CONSTANTS ===
    public const COLUMN_ACCOUNT_ID = 'account_id';

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
}
