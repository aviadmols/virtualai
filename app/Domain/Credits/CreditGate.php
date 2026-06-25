<?php

namespace App\Domain\Credits;

use App\Models\Account;

/**
 * CreditGate — does the MERCHANT have credits? One of the two independent gates
 * (the other is LeadGate: is the END USER under the free limit). Both must pass;
 * they NEVER collapse into one.
 *
 * The gate is read-only and returns a TYPED CreditDenied result — never throws for
 * the out-of-credits path, never a 500. A caller branches on ->passed; the widget
 * renders an "out of credits" screen on a denial.
 *
 * Pass iff: the account is active AND spendable ≥ estimate, where
 *   spendable = balance_micro_usd − reserved_micro_usd
 * so in-flight reservations are already subtracted (two concurrent generations
 * can't both pass against the same balance).
 *
 * Usage: CreditGate::for($account)->assertCanSpend($estimateMicroUsd)
 */
final class CreditGate
{
    // === CONSTANTS ===
    private const LOW_BALANCE_CONFIG_KEY = 'trayon.credits.low_balance_micro_usd';

    private function __construct(
        private readonly Account $account,
    ) {}

    public static function for(Account $account): self
    {
        return new self($account);
    }

    /**
     * Check the account can spend $estimateMicroUsd. Returns a typed result
     * (passed / denied with a reason) — the caller never catches an exception here.
     */
    public function assertCanSpend(int $estimateMicroUsd): CreditDenied
    {
        $spendable = $this->account->spendableMicroUsd();

        if (! $this->account->isActive()) {
            return CreditDenied::deny(CreditDenied::REASON_ACCOUNT_INACTIVE, $spendable, $estimateMicroUsd);
        }

        if ($spendable < $estimateMicroUsd) {
            return CreditDenied::deny(CreditDenied::REASON_INSUFFICIENT_CREDITS, $spendable, $estimateMicroUsd);
        }

        return CreditDenied::pass($spendable, $estimateMicroUsd);
    }

    /** Convenience boolean for callers that only need a yes/no. */
    public function canSpend(int $estimateMicroUsd): bool
    {
        return $this->assertCanSpend($estimateMicroUsd)->passed;
    }

    /**
     * True when spendable credit has dropped to/below the low-balance warning
     * threshold (config). A WARN signal for the merchant UI, not a gate — the gate
     * itself is the exact assertCanSpend() check above.
     */
    public function isLowBalance(): bool
    {
        return $this->account->spendableMicroUsd() <= (int) config(self::LOW_BALANCE_CONFIG_KEY);
    }
}
