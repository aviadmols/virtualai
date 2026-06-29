<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Support\Tenant;
use Database\Factories\AccountFactory;
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
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public const DEFAULT_LOCALE = 'en';

    // Activity-detail key carrying the optional super-admin reason on a suspend.
    private const META_REASON = 'reason';

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

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Suspend the account (super-admin control-plane action). IDEMPOTENT: a no-op on an
     * already-suspended account (no second status write, no duplicate trace). On a real
     * change, writes the status and records an account_suspended event.
     *
     * A suspended account is already DENIED at generation start: CreditGate.isActive()
     * is false, so GenerateTryOnJob cancels with the typed ACCOUNT_INACTIVE failure code
     * (never a 500). This method only flips the gate input — no extra wiring needed.
     *
     * @return bool true if the status changed; false if already suspended (no-op)
     */
    public function suspend(?string $reason = null): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        $this->forceFill(['status' => self::STATUS_SUSPENDED])->save();
        $this->recordStatusEvent(ActivityEvent::KIND_ACCOUNT_SUSPENDED, [self::META_REASON => $reason]);

        return true;
    }

    /**
     * Restore a suspended account to active. IDEMPOTENT: a no-op on an already-active
     * account. On a real change, writes the status and records an account_restored event;
     * the next generation passes the CreditGate again.
     *
     * @return bool true if the status changed; false if already active (no-op)
     */
    public function restore(): bool
    {
        if ($this->isActive()) {
            return false;
        }

        $this->forceFill(['status' => self::STATUS_ACTIVE])->save();
        $this->recordStatusEvent(ActivityEvent::KIND_ACCOUNT_RESTORED, []);

        return true;
    }

    /**
     * Trace an account status change on the timeline. ActivityEvent is BelongsToAccount,
     * so it is recorded INSIDE the account's own bound tenant (the trace is best-effort;
     * ActivityRecorder swallows its own errors and never blocks the status write).
     *
     * @param  array<string,mixed>  $details
     */
    private function recordStatusEvent(string $kind, array $details): void
    {
        Tenant::run($this, function () use ($kind, $details): void {
            app(ActivityRecorder::class)->record(
                kind: $kind,
                subject: $this,
                details: $details,
                actor: ActivityEvent::ACTOR_SYSTEM,
            );
        });
    }
}
