<?php

namespace App\Domain\Platform;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Support\Tenant;
use Illuminate\Support\Str;

/**
 * PlatformCreditAdjustment — the super-admin "manual credit adjustment" action (the
 * platform account-credits screen). A THIN wrapper over the existing
 * CreditLedgerService::adjustment() — it NEVER writes a bare balance; every change is one
 * append-only `adjustment` ledger row, and the service stamps balance_after + records the
 * credit_adjusted activity event under the account's bound tenant.
 *
 * Guarded by PlatformGuard (super-admin only) — like the platform read seams.
 *
 * IDEMPOTENCY: a deterministic key admin-adjust:{ref} (ref defaults to a fresh UUID) is
 * folded into the ledger key adjustment:{account}:admin-adjust:{ref}. Re-running with the
 * SAME ref is a no-op (the service returns the existing row) — a double-submitted admin
 * form can never double-adjust.
 *
 * FLOOR POLICY: a DOWNWARD adjustment is clamped so the balance never goes below 0 (a
 * super-admin clawing back more than the account holds floors at 0, never negative). An
 * upward adjustment is unbounded. This is the action's floor (the ledger writer itself
 * does not floor).
 */
final class PlatformCreditAdjustment
{
    // === CONSTANTS ===
    // The admin namespace folded into the deterministic idempotency ref.
    private const REF_PREFIX = 'admin-adjust';

    private const BALANCE_FLOOR = 0;

    public function __construct(
        private readonly CreditLedgerService $ledger,
    ) {}

    /**
     * Apply a signed micro-USD adjustment to an account's credit. Super-admin only.
     *
     * @param  int  $amountMicroUsd  positive = grant credit, negative = claw back
     * @param  string|null  $reference  a stable admin ref for idempotency (UUID if null)
     * @param  string  $description  the human-facing ledger line
     */
    public function apply(
        Account $account,
        int $amountMicroUsd,
        ?string $reference = null,
        string $description = '',
    ): CreditLedger {
        PlatformGuard::assert();

        $ref = $reference ?? (string) Str::uuid();
        $key = IdempotencyKey::forAdjustment($account->getKey(), self::REF_PREFIX.':'.$ref);

        // The adjustment runs inside the account's bound tenant so the ledger row + the
        // credit_adjusted activity event are account-scoped (the row lock lives in the
        // service). amount_micro_usd carries the FLOOR-clamped value for a downward move.
        return Tenant::run($account, function () use ($account, $amountMicroUsd, $key, $description): CreditLedger {
            $effective = $this->clampToFloor($account, $amountMicroUsd);

            return $this->ledger->adjustment(
                account: $account,
                amountMicroUsd: $effective,
                idempotencyKey: $key,
                description: $description,
                meta: ['source' => self::REF_PREFIX, 'requested_micro_usd' => $amountMicroUsd],
            );
        });
    }

    /**
     * Clamp a downward adjustment so the resulting balance never drops below 0. Reads the
     * current balance fresh; an upward adjustment is returned unchanged. A request that
     * would over-draw is reduced to exactly the available balance (floor at 0).
     */
    private function clampToFloor(Account $account, int $amountMicroUsd): int
    {
        if ($amountMicroUsd >= 0) {
            return $amountMicroUsd; // upward is unbounded
        }

        $balance = (int) $account->fresh()->balance_micro_usd;
        $maxClawback = max(self::BALANCE_FLOOR, $balance);

        // e.g. balance 1_000_000, request -3_000_000 -> clamp to -1_000_000 (floor at 0).
        return -min(abs($amountMicroUsd), $maxClawback);
    }
}
