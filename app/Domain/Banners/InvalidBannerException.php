<?php

namespace App\Domain\Banners;

use RuntimeException;

/**
 * InvalidBannerException — a banner write was rejected because a value is invalid or a
 * state move is illegal. A typed, EXPECTED validation outcome (the editor surfaces it as a
 * soft field error), distinct from a 500. NOTHING is persisted when this is thrown — the
 * service validates the whole patch BEFORE any write. Carries the offending field + a
 * stable reason the UI maps to a message.
 */
final class InvalidBannerException extends RuntimeException
{
    // === CONSTANTS ===
    public const REASON_INVALID_NAME = 'invalid_name';

    public const REASON_INVALID_COMPOSITION = 'invalid_composition';

    public const REASON_INVALID_TARGET_URL = 'invalid_target_url';

    public const REASON_INVALID_OVERLAY = 'invalid_overlay';

    public const REASON_INVALID_ALT_TEXT = 'invalid_alt_text';

    public const REASON_NO_ARTWORK = 'no_artwork';

    public const REASON_ASSET_NOT_SELECTABLE = 'asset_not_selectable';

    public const REASON_INVALID_PLACEMENTS = 'invalid_placements';

    public const REASON_INVALID_RULES = 'invalid_rules';

    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function make(string $field, string $reason, string $detail): self
    {
        return new self($field, $reason, 'banner "'.$field.'" is invalid: '.$detail);
    }
}
