<?php

namespace App\Domain\Ai;

/**
 * AspectRatios — the curated set of proportions a merchant can pick for a generation, on top of the
 * operation's configured default. Values are the W:H strings every provider honours; the label
 * suffix keys the i18n catalog (ai_choices.aspect.*). normalize() drops anything outside this list,
 * so a stale or hostile value can never reach a provider.
 */
final class AspectRatios
{
    // === CONSTANTS ===
    // value (W:H, provider-honoured) => i18n label suffix.
    public const OPTIONS = [
        '1:1' => 'square',
        '4:5' => 'portrait',
        '2:3' => 'portrait_tall',
        '9:16' => 'story',
        '3:2' => 'landscape',
        '16:9' => 'wide',
    ];

    /** The value to persist: a known ratio, or null (= keep the operation's configured default). */
    public static function normalize(?string $value): ?string
    {
        return array_key_exists(trim((string) $value), self::OPTIONS) ? trim((string) $value) : null;
    }
}
