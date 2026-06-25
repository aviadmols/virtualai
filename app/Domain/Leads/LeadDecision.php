<?php

namespace App\Domain\Leads;

/**
 * LeadDecision — the typed result of the LeadGate. NOT an exception.
 *
 * The end user being out of free tries is a normal funnel outcome, not a server
 * error: the widget renders a "signup required" form, never a 500. The gate
 * returns one of these; a caller branches on ->allowed.
 *
 * Independent of the credit gate's CreditDenied — the two gates never collapse.
 */
final readonly class LeadDecision
{
    // === CONSTANTS ===
    public const REASON_SIGNUP_REQUIRED = 'signup_required';
    public const REASON_POST_SIGNUP_LIMIT = 'post_signup_limit_reached';

    private function __construct(
        public bool $allowed,
        public ?string $reason,
        public bool $signupRequired,
        public int $freeRemaining,
    ) {}

    /** The end user may try (free tries remain, unlimited, or registered + within grant). */
    public static function allow(int $freeRemaining = 0): self
    {
        return new self(true, null, false, $freeRemaining);
    }

    /** Blocked: the free-tries limit is reached and signup is required to continue. */
    public static function signupRequired(): self
    {
        return new self(false, self::REASON_SIGNUP_REQUIRED, true, 0);
    }

    /** Blocked: the end user is registered but their post-signup grant is exhausted. */
    public static function postSignupLimitReached(): self
    {
        return new self(false, self::REASON_POST_SIGNUP_LIMIT, false, 0);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }
}
