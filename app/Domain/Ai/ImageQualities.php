<?php

namespace App\Domain\Ai;

/**
 * ImageQualities — the quality levels a merchant can pick, on top of the operation default. Only the
 * values the providers already accept (standard | high) are offered, so a selector can never produce
 * a quality a model rejects. normalize() drops anything else (= keep the operation's default).
 */
final class ImageQualities
{
    // === CONSTANTS ===
    public const STANDARD = 'standard';

    public const HIGH = 'high';

    // value => i18n label suffix (ai_choices.quality.*).
    public const OPTIONS = [
        self::STANDARD => 'standard',
        self::HIGH => 'high',
    ];

    /** The value to persist: a known quality, or null (= keep the operation's configured default). */
    public static function normalize(?string $value): ?string
    {
        return array_key_exists(trim((string) $value), self::OPTIONS) ? trim((string) $value) : null;
    }
}
