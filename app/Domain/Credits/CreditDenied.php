<?php

namespace App\Domain\Credits;

/**
 * CreditDenied — the typed "out of credits" result. NOT an exception.
 *
 * A merchant being out of credits is a normal business outcome, not a server
 * error: the widget renders an "out of credits" screen, never a 500. The credit
 * gate returns one of these (passed=false) or the passing variant (passed=true);
 * a caller branches on ->passed, it never catches a throwable for this path.
 *
 * Kept independent from the lead gate's result (the two gates never collapse).
 */
final readonly class CreditDenied
{
    // === CONSTANTS ===
    public const REASON_INSUFFICIENT_CREDITS = 'insufficient_credits';
    public const REASON_ACCOUNT_INACTIVE = 'account_inactive';

    private function __construct(
        public bool $passed,
        public ?string $reason,
        public int $spendableMicroUsd,
        public int $estimateMicroUsd,
    ) {}

    /** The gate passed: the account can spend the estimate. */
    public static function pass(int $spendableMicroUsd, int $estimateMicroUsd): self
    {
        return new self(true, null, $spendableMicroUsd, $estimateMicroUsd);
    }

    /** The gate denied: spendable < estimate, or the account is not active. */
    public static function deny(string $reason, int $spendableMicroUsd, int $estimateMicroUsd): self
    {
        return new self(false, $reason, $spendableMicroUsd, $estimateMicroUsd);
    }

    public function denied(): bool
    {
        return ! $this->passed;
    }
}
