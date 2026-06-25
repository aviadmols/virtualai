<?php

namespace App\Domain\Scan\Selectors;

use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\ScanConstants;

/**
 * SelectorVerifier — the count-verification gate. A selector is only trustworthy
 * when it resolves to EXACTLY ONE element in the fetched DOM. This class reports
 * the live match count + a verification weight (full for ==1, hard-penalised for
 * 0 or >1) so the detector can pick a single-match selector and flag the rest.
 *
 * Used both at scan time (verify detected selectors) and at confirm time (live
 * re-verify a merchant's manually-entered selector).
 */
final class SelectorVerifier
{
    public function __construct(
        private readonly ScanDom $dom,
    ) {}

    /** Live match count for a selector in the fetched DOM. */
    public function count(string $selector): int
    {
        return $this->dom->count($selector);
    }

    /** True only when the selector resolves to exactly one element. */
    public function resolvesToOne(string $selector): bool
    {
        return $this->dom->matchesExactlyOne($selector);
    }

    /** The verification weight: 1.0 for one match, penalised for 0 / many. */
    public function verificationWeight(int $matchedCount): float
    {
        return match (true) {
            $matchedCount === 1 => ScanConstants::SELECTOR_MATCH_ONE_WEIGHT,
            $matchedCount > 1 => ScanConstants::SELECTOR_MATCH_MULTI_WEIGHT,
            default => ScanConstants::SELECTOR_MATCH_ZERO_WEIGHT,
        };
    }

    /**
     * The merchant-facing verdict for a manually entered selector (used by the
     * confirm/correct contract): how many elements it resolves to + whether it is
     * safe (exactly one).
     *
     * @return array{selector: string, matched_count: int, resolves_to_one: bool, strategy: string}
     */
    public function verdict(string $selector): array
    {
        $count = $this->count($selector);

        return [
            'selector' => $selector,
            'matched_count' => $count,
            'resolves_to_one' => $count === 1,
            'strategy' => SelectorStrategy::classify($selector),
        ];
    }

    /**
     * Score a single candidate: source-weight (strategy) × verification weight.
     * Returns confidence + matchedCount + strategy + needsReview.
     *
     * @return array{confidence: float, matched_count: int, strategy: string, needs_review: bool}
     */
    public function score(string $selector): array
    {
        $count = $this->count($selector);
        $strategy = SelectorStrategy::classify($selector);

        $confidence = SelectorStrategy::weight($strategy) * $this->verificationWeight($count);

        $needsReview = $count !== 1
            || SelectorStrategy::alwaysReview($strategy)
            || $confidence < ScanConstants::REVIEW_FLOOR;

        return [
            'confidence' => $confidence,
            'matched_count' => $count,
            'strategy' => $strategy,
            'needs_review' => $needsReview,
        ];
    }
}
