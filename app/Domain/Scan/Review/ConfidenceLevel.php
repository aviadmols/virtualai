<?php

namespace App\Domain\Scan\Review;

use App\Domain\Scan\ScanConstants;

/**
 * ConfidenceLevel — the SINGLE bucketing source: a numeric scan confidence (or a
 * "nothing was detected" signal) becomes exactly one of the four contract levels
 * the A4 review form + the badge map (design-tokens §5) key off:
 *
 *   high        ≥ REVIEW_FLOOR        calm, ready, pre-confirmable
 *   medium      ≥ LEVEL_MEDIUM_FLOOR  "please confirm", pre-confirmable
 *   low         > 0                   flagged — must be reviewed before confirm
 *   not_detected  null / 0            empty — manual value required before confirm
 *
 * The bucketing thresholds are a pdp-scanner decision (the token map keys the
 * bucketed level, not the raw score). low + not_detected are the BLOCKING levels:
 * a row at either level blocks confirm until the merchant touches it — the
 * no-auto-approve gate's atom. Every other class that needs a level asks here;
 * the thresholds never drift across the codebase.
 */
final readonly class ConfidenceLevel
{
    private function __construct(
        public string $level,
    ) {}

    /**
     * Bucket a numeric confidence into a level. A null/absent value means the
     * field/selector was never detected (not the same as a low score) so the UI
     * shows the manual-entry path rather than a "low confidence" flag.
     */
    public static function fromScore(?float $confidence, bool $detected = true): self
    {
        if (! $detected || $confidence === null) {
            return new self(ScanConstants::LEVEL_NOT_DETECTED);
        }

        return new self(match (true) {
            $confidence >= ScanConstants::REVIEW_FLOOR => ScanConstants::LEVEL_HIGH,
            $confidence >= ScanConstants::LEVEL_MEDIUM_FLOOR => ScanConstants::LEVEL_MEDIUM,
            $confidence > 0.0 => ScanConstants::LEVEL_LOW,
            default => ScanConstants::LEVEL_NOT_DETECTED,
        });
    }

    /** The explicit not_detected level (empty field / no selector found). */
    public static function notDetected(): self
    {
        return new self(ScanConstants::LEVEL_NOT_DETECTED);
    }

    /**
     * True when this level BLOCKS confirm until the merchant reviews/edits the row.
     * low + not_detected block; high + medium are pre-confirmable. The single
     * predicate the confirm gate, the read model, and the confirm action all read.
     */
    public function blocksConfirm(): bool
    {
        return in_array($this->level, ScanConstants::LEVELS_BLOCKING_CONFIRM, true);
    }

    /** The badge i18n key for this level (design-tokens §5). */
    public function i18nKey(): string
    {
        return ScanConstants::LEVEL_I18N_KEY[$this->level];
    }

    public function is(string $level): bool
    {
        return $this->level === $level;
    }

    public function __toString(): string
    {
        return $this->level;
    }
}
