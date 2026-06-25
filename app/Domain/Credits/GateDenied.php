<?php

namespace App\Domain\Credits;

/**
 * GateDenied — the typed result of the UsageGate (per-account / per-site usage limits
 * and optional plan-feature gates). NOT an exception.
 *
 * A usage cap or a plan-feature lock is a normal business outcome, not a server error:
 * the UI renders "too many tries, slow down" (a rate cap -> HTTP 429 with Retry-After)
 * or "upgrade to unlock" (a feature gate), never a 500. The gate returns one of these;
 * a caller branches on ->allowed.
 *
 * Independent of CreditDenied (merchant credits) and LeadDecision (end-user free tries)
 * — the gates compose, they never collapse. The widget API may hit all of them on one
 * request; each returns its own typed result.
 */
final readonly class GateDenied
{
    // === CONSTANTS ===
    public const REASON_RATE_LIMITED = 'rate_limited';   // a per-account/site RPM cap -> 429
    public const REASON_PLAN_LIMIT = 'plan_limit';       // a countable plan limit (e.g. max_sites)
    public const REASON_PLAN_FEATURE = 'plan_feature';   // a boolean plan feature is off -> upgrade

    private function __construct(
        public bool $allowed,
        public ?string $reason,
        public ?string $limitKey,
        public ?int $retryAfterSeconds,
    ) {}

    /** The gate passed. */
    public static function allow(): self
    {
        return new self(true, null, null, null);
    }

    /** A per-account/site rate cap was hit -> the caller returns a typed 429. */
    public static function rateLimited(string $limitKey, int $retryAfterSeconds): self
    {
        return new self(false, self::REASON_RATE_LIMITED, $limitKey, max(1, $retryAfterSeconds));
    }

    /** A countable plan limit was reached (e.g. max_sites) -> "upgrade to unlock". */
    public static function planLimit(string $limitKey): self
    {
        return new self(false, self::REASON_PLAN_LIMIT, $limitKey, null);
    }

    /** A boolean plan feature is off (e.g. custom_branding) -> "upgrade to unlock". */
    public static function planFeature(string $limitKey): self
    {
        return new self(false, self::REASON_PLAN_FEATURE, $limitKey, null);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /** True for the rate-cap reason (the only one that maps to HTTP 429). */
    public function isRateLimited(): bool
    {
        return $this->reason === self::REASON_RATE_LIMITED;
    }
}
