<?php

namespace App\Domain\Banners;

use App\Models\Banner;

/**
 * BannerContent — the per-banner CONTENT schema (single source of truth for the editor's
 * name / composition / target_url / overlay / alt_text fields), mirroring ClubConfig's
 * sanitize contract. Validates a merchant-submitted content patch and returns the sanitized
 * set; throws InvalidBannerException on any bad value (nothing is persisted).
 *
 * Overlay text is merchant DATA, never a template: it is stored as scalars and rendered by
 * the widget as text nodes (no strtr, no Blade). Placements + rules are validated by their
 * own sanitizers (Phase 3 / Phase 4); this class owns only the content fields.
 */
final class BannerContent
{
    // === CONSTANTS ===
    public const KEY_NAME = 'name';

    public const KEY_COMPOSITION = 'composition';

    public const KEY_TARGET_URL = 'target_url';

    public const KEY_ALT_TEXT = 'alt_text';

    public const KEY_OVERLAY = 'overlay';

    // Overlay sub-keys.
    public const OVERLAY_HEADLINE = 'headline';

    public const OVERLAY_SUBTEXT = 'subtext';

    public const OVERLAY_CTA_LABEL = 'cta_label';

    public const OVERLAY_KEYS = [
        self::OVERLAY_HEADLINE,
        self::OVERLAY_SUBTEXT,
        self::OVERLAY_CTA_LABEL,
    ];

    // Length caps (chars).
    public const NAME_MAX = 120;

    public const TARGET_URL_MAX = 2048;

    public const ALT_TEXT_MAX = 240;

    public const HEADLINE_MAX = 120;

    public const SUBTEXT_MAX = 240;

    public const CTA_LABEL_MAX = 40;

    // A click target must be a real web link — only http(s) (no javascript:, data:, etc.).
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Validate a content patch and return the sanitized fields present in $input. Only keys
     * present are returned (so a partial update touches only those). Throws on any bad value.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array
    {
        $clean = [];

        if (array_key_exists(self::KEY_NAME, $input)) {
            $clean[self::KEY_NAME] = self::validName($input[self::KEY_NAME]);
        }

        if (array_key_exists(self::KEY_COMPOSITION, $input)) {
            $clean[self::KEY_COMPOSITION] = self::validComposition($input[self::KEY_COMPOSITION]);
        }

        if (array_key_exists(self::KEY_TARGET_URL, $input)) {
            $clean[self::KEY_TARGET_URL] = self::validTargetUrl($input[self::KEY_TARGET_URL]);
        }

        if (array_key_exists(self::KEY_ALT_TEXT, $input)) {
            $clean[self::KEY_ALT_TEXT] = self::validText(self::KEY_ALT_TEXT, $input[self::KEY_ALT_TEXT], self::ALT_TEXT_MAX, InvalidBannerException::REASON_INVALID_ALT_TEXT);
        }

        if (array_key_exists(self::KEY_OVERLAY, $input)) {
            $clean[self::KEY_OVERLAY] = self::validOverlay($input[self::KEY_OVERLAY]);
        }

        return $clean;
    }

    /** A required, non-blank name within the length cap. */
    private static function validName(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '') {
            throw InvalidBannerException::make(self::KEY_NAME, InvalidBannerException::REASON_INVALID_NAME, 'a name is required');
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw InvalidBannerException::make(self::KEY_NAME, InvalidBannerException::REASON_INVALID_NAME, 'must be at most '.self::NAME_MAX.' characters');
        }

        return $name;
    }

    private static function validComposition(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, Banner::COMPOSITIONS, true)) {
            throw InvalidBannerException::make(self::KEY_COMPOSITION, InvalidBannerException::REASON_INVALID_COMPOSITION, 'must be one of: '.implode(', ', Banner::COMPOSITIONS));
        }

        return $value;
    }

    /** A nullable http(s) URL within the length cap (blank => null; a click needs a real link). */
    private static function validTargetUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $url = is_string($value) ? trim($value) : '';

        if ($url === '') {
            return null;
        }

        if (mb_strlen($url) > self::TARGET_URL_MAX
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($url, PHP_URL_SCHEME), self::ALLOWED_SCHEMES, true)) {
            throw InvalidBannerException::make(self::KEY_TARGET_URL, InvalidBannerException::REASON_INVALID_TARGET_URL, 'must be a valid http(s) URL');
        }

        return $url;
    }

    /**
     * The overlay object: only the three known scalar text keys, each within its cap. A blank
     * value becomes null. Unknown keys are rejected. Non-object input is rejected.
     *
     * @return array<string,string|null>
     */
    private static function validOverlay(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value) || array_is_list($value)) {
            throw InvalidBannerException::make(self::KEY_OVERLAY, InvalidBannerException::REASON_INVALID_OVERLAY, 'must be an object keyed by headline/subtext/cta_label');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::OVERLAY_KEYS, true)) {
                throw InvalidBannerException::make(self::KEY_OVERLAY, InvalidBannerException::REASON_INVALID_OVERLAY, 'has an unknown key "'.$key.'"');
            }
        }

        $caps = [
            self::OVERLAY_HEADLINE => self::HEADLINE_MAX,
            self::OVERLAY_SUBTEXT => self::SUBTEXT_MAX,
            self::OVERLAY_CTA_LABEL => self::CTA_LABEL_MAX,
        ];

        $clean = [];

        foreach (self::OVERLAY_KEYS as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $text = self::validText(self::KEY_OVERLAY.'.'.$key, $value[$key], $caps[$key], InvalidBannerException::REASON_INVALID_OVERLAY);
            if ($text !== null) {
                $clean[$key] = $text;
            }
        }

        return $clean;
    }

    /** A nullable trimmed scalar text within a cap (blank => null). */
    private static function validText(string $field, mixed $value, int $max, string $reason): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidBannerException::make($field, $reason, 'must be text');
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw InvalidBannerException::make($field, $reason, 'must be at most '.$max.' characters');
        }

        return $text;
    }
}
