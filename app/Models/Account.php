<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Account — THE TENANT and the isolation boundary.
 *
 * NOT account-scoped (it scopes everything else). Holds the credit balance
 * (integer micro-USD) + status. The ledger / reservation logic is Phase 5;
 * Phase 2 only establishes the model + tenant columns.
 */
class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public const DEFAULT_LOCALE = 'en';

    // The money columns are DELIBERATELY NOT fillable (S4): only CreditLedgerService
    // and ReservationManager mutate them, and only via forceFill() under a row lock.
    // A mass-assigned balance/reserved is a money-safety hole — never reachable here.
    protected $fillable = [
        'name',
        'status',
        'locale',
        'billing_email',
        'company_name',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'locale' => self::DEFAULT_LOCALE,
        'balance_micro_usd' => 0,
        'reserved_micro_usd' => 0,
    ];

    protected function casts(): array
    {
        return [
            'balance_micro_usd' => 'integer',
            'reserved_micro_usd' => 'integer',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Spendable credit = balance − reserved (integer micro-USD). */
    public function spendableMicroUsd(): int
    {
        return $this->balance_micro_usd - $this->reserved_micro_usd;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
