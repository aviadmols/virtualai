<?php

namespace App\Domain\Scan\Review;

/**
 * SelectorTestResult — the typed outcome of testing one selector (detected OR
 * merchant-entered) against a (re-fetched) page: does it resolve to EXACTLY ONE
 * element? This is the shape the per-selector "Test selector" action returns.
 *
 * Four outcomes the UI maps to scan.selector.test_* feedback:
 *   matched     — exactly one element (the only safe count; scan.selector.test_ok)
 *   multiple    — >1 elements (the classic add-to-cart dupe; needs disambiguation)
 *   not_found   — 0 elements (scan.selector.test_fail)
 *   error       — the page could not be re-fetched / the selector is malformed
 *
 * Built from SelectorVerifier's count verdict (the same exactly-one gate used at
 * scan time), so test feedback and scan-time selection never disagree.
 */
final readonly class SelectorTestResult
{
    // === OUTCOMES ===
    public const OUTCOME_MATCHED = 'matched';

    public const OUTCOME_MULTIPLE = 'multiple';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_ERROR = 'error';

    // The i18n feedback key per outcome (i18n catalog scan.selector.test_*).
    private const OUTCOME_I18N = [
        self::OUTCOME_MATCHED => 'scan.selector.test_ok',
        self::OUTCOME_MULTIPLE => 'scan.selector.test_multiple',
        self::OUTCOME_NOT_FOUND => 'scan.selector.test_fail',
        self::OUTCOME_ERROR => 'scan.selector.test_error',
    ];

    public function __construct(
        public string $selector,
        public string $outcome,
        public int $matchedCount,
        public string $strategy,
        public ?string $errorReason = null,
    ) {}

    /**
     * Build the typed result from a raw match count + strategy (the SelectorVerifier
     * verdict shape). The single place a count becomes an outcome.
     */
    public static function fromCount(string $selector, int $matchedCount, string $strategy): self
    {
        $outcome = match (true) {
            $matchedCount === 1 => self::OUTCOME_MATCHED,
            $matchedCount > 1 => self::OUTCOME_MULTIPLE,
            default => self::OUTCOME_NOT_FOUND,
        };

        return new self($selector, $outcome, $matchedCount, $strategy);
    }

    /** A test that could not run (page un-fetchable / malformed selector). */
    public static function error(string $selector, string $strategy, string $reason): self
    {
        return new self($selector, self::OUTCOME_ERROR, 0, $strategy, $reason);
    }

    /** True only when the selector resolves to exactly one element — the safe count. */
    public function resolvesToOne(): bool
    {
        return $this->outcome === self::OUTCOME_MATCHED;
    }

    /** The i18n feedback key for this outcome. */
    public function i18nKey(): string
    {
        return self::OUTCOME_I18N[$this->outcome];
    }

    public function toArray(): array
    {
        return [
            'selector' => $this->selector,
            'outcome' => $this->outcome,
            'matched_count' => $this->matchedCount,
            'strategy' => $this->strategy,
            'resolves_to_one' => $this->resolvesToOne(),
            'i18n_key' => $this->i18nKey(),
            'error_reason' => $this->errorReason,
        ];
    }
}
