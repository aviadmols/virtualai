<?php

namespace App\Domain\Scan\Selectors;

/**
 * DetectedSelector — a verified selector for one widget role.
 *
 * Carries the primary selector, a fallback chain (2-3 distinct resolvers so the
 * widget degrades instead of failing), the per-selector confidence, how many
 * elements the primary matched in the fetched DOM (1 is the only safe count), the
 * winning strategy (id/data/aria/semantic/class/positional), and whether it needs
 * merchant review (a 0/>1 match or a positional-only selector always does).
 */
final readonly class DetectedSelector
{
    /** @param  array<int,string>  $fallbackChain */
    public function __construct(
        public string $role,
        public ?string $primary,
        public array $fallbackChain,
        public float $confidence,
        public int $matchedCount,
        public string $strategy,
        public bool $needsReview,
    ) {}

    /** The persisted shape stored on the Product / Site detected_selectors. */
    public function toArray(): array
    {
        return [
            'primary' => $this->primary,
            'fallback_chain' => $this->fallbackChain,
            'confidence' => round($this->confidence, 3),
            'matched_count' => $this->matchedCount,
            'strategy' => $this->strategy,
            'needs_review' => $this->needsReview,
        ];
    }
}
