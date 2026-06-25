<?php

namespace App\Domain\Ai;

/**
 * ParsedCost — the cost of a generation, or an honest "unavailable".
 *
 * Cost is NEVER guessed. It comes from the inline usage.cost, or the generation
 * cost endpoint, or it is flagged unavailable. When unavailable, costUsd is null
 * and laravel-backend decides what to do (release / reconcile) — this layer never
 * invents a number. estimatedCostMicroUsd carries the operation's estimate so the
 * caller can reconcile, but it is NOT presented as the real cost.
 *
 * MONEY-PATH INVARIANT (enforced in the constructor): "available" and "non-null
 * cost" are the SAME thing. A null costUsd can NEVER be presented as available —
 * the constructor normalizes any contradictory combination to UNAVAILABLE. This
 * makes `chargeMicroUsd(null, ...)` structurally impossible to reach from a
 * "cost available" result: laravel-backend charges only when available === true,
 * and available === true now guarantees a non-null cost.
 */
final readonly class ParsedCost
{
    // === CONSTANTS ===
    // Where the cost came from — stamped on every ParsedCost for masked logging.
    public const SOURCE_INLINE = 'inline';
    public const SOURCE_ENDPOINT = 'generation_endpoint';
    public const SOURCE_UNAVAILABLE = 'unavailable';

    public ?float $costUsd;

    public bool $available;

    public string $source;

    public ?int $estimatedCostMicroUsd;

    public function __construct(
        ?float $costUsd,
        bool $available,
        string $source,
        ?int $estimatedCostMicroUsd = null,
    ) {
        // Enforce the coupling: a null cost is ALWAYS unavailable, regardless of
        // what the caller asked for. There is no "available but null" state — it
        // collapses to unavailable so a null cost can never feed the charge path.
        if ($costUsd === null) {
            $available = false;
            $source = self::SOURCE_UNAVAILABLE;
        }

        $this->costUsd = $costUsd;
        $this->available = $available;
        $this->source = $source;
        $this->estimatedCostMicroUsd = $estimatedCostMicroUsd;
    }

    public static function inline(float $costUsd): self
    {
        return new self($costUsd, true, self::SOURCE_INLINE);
    }

    public static function fromEndpoint(float $costUsd): self
    {
        return new self($costUsd, true, self::SOURCE_ENDPOINT);
    }

    public static function unavailable(?int $estimatedCostMicroUsd = null): self
    {
        return new self(null, false, self::SOURCE_UNAVAILABLE, $estimatedCostMicroUsd);
    }
}
