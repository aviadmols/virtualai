<?php

namespace App\Http\Widget;

/**
 * WidgetGateDecision — the combined, typed outcome of the three INDEPENDENT widget gates
 * (UsageGate rate cap, CreditGate merchant credits, LeadGate end-user free tries) run as
 * a fast pre-dispatch check at the HTTP boundary.
 *
 * The gates never collapse into one: each is evaluated on its own; this object just
 * carries the FIRST blocking outcome (by the contract precedence) plus the data the
 * widget needs to render the right screen. A pass carries the lead's free_remaining so
 * the widget can update its free-tries chip.
 *
 * A denial here means: NO Generation row, NO job dispatched, NO OpenRouter call, NO
 * charge. The denial is a typed result the controller turns into typed JSON — never a
 * 500, never a charge.
 */
final readonly class WidgetGateDecision
{
    // === CONSTANTS ===
    public const REASON_SIGNUP_REQUIRED = 'signup_required';
    public const REASON_POST_SIGNUP_LIMIT = 'post_signup_limit_reached';
    public const REASON_INSUFFICIENT_CREDITS = 'insufficient_credits';
    public const REASON_ACCOUNT_INACTIVE = 'account_inactive';
    public const REASON_RATE_LIMITED = 'rate_limited';

    private function __construct(
        public bool $allowed,
        public ?string $reason,
        public int $freeRemaining,
        public ?int $retryAfterSeconds,
    ) {}

    public static function allow(int $freeRemaining = 0): self
    {
        return new self(true, null, $freeRemaining, null);
    }

    public static function deny(string $reason, int $retryAfterSeconds = null): self
    {
        return new self(false, $reason, 0, $retryAfterSeconds);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }
}
