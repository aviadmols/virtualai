<?php

namespace App\Domain\Banners;

/**
 * BannerPlacements — the per-banner PLACEMENT schema: a flat list of host-page spots where the
 * widget injects the banner, each a { selector, position } pair. The selector is picked visually
 * (the reused sandboxed-iframe picker) and verified SERVER-SIDE to resolve-to-one before it is
 * stored; it is config the widget hands to querySelector — NEVER eval'd — so the allow-list
 * (identical to ClubConfig/WidgetAppearance) + a length cap + a per-banner count cap are the whole
 * defence. Position mirrors the button-placement semantics (before/after a node, or prepend/append
 * inside it). Which PAGES a placement actually shows on is the rules layer's job (Phase 4).
 */
final class BannerPlacements
{
    // === CONSTANTS ===
    public const KEY_SELECTOR = 'selector';

    public const KEY_POSITION = 'position';

    // Where the banner is injected relative to the picked element.
    public const POSITION_BEFORE = 'before';

    public const POSITION_AFTER = 'after';

    public const POSITION_PREPEND = 'prepend';

    public const POSITION_APPEND = 'append';

    public const POSITIONS = [
        self::POSITION_BEFORE,
        self::POSITION_AFTER,
        self::POSITION_PREPEND,
        self::POSITION_APPEND,
    ];

    public const POSITION_DEFAULT = self::POSITION_AFTER;

    public const SELECTOR_MAX = 500;

    // A merchant needs a few spots, not many.
    public const MAX = 8;

    // Identical CSS-selector allow-list to ClubConfig/WidgetAppearance: only characters that
    // appear in real selectors; { } < ; / ` are excluded so no scriptable string can pass.
    private const SELECTOR_PATTERN = '/^[\w #.>~+*^$|:,()\[\]="\'-]+$/';

    /**
     * Validate a placements list and return the sanitized set: a list of { selector, position }.
     * Deduplicated by selector (last position wins), capped at MAX. Any bad value throws
     * InvalidBannerException (nothing persisted). A null/empty input is a valid empty list.
     *
     * @return array<int,array{selector:string,position:string}>
     */
    public static function sanitize(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw self::reject('must be a list of placements');
        }

        $clean = [];
        $seen = [];

        foreach ($value as $entry) {
            [$selector, $position] = self::validEntry($entry);

            // Dedupe by selector: a re-pick of the same element updates its position.
            if (array_key_exists($selector, $seen)) {
                $clean[$seen[$selector]][self::KEY_POSITION] = $position;

                continue;
            }

            $seen[$selector] = count($clean);
            $clean[] = [self::KEY_SELECTOR => $selector, self::KEY_POSITION => $position];
        }

        if (count($clean) > self::MAX) {
            throw self::reject('has too many placements (max '.self::MAX.')');
        }

        return $clean;
    }

    /**
     * Validate one { selector, position } entry.
     *
     * @return array{0:string,1:string}
     */
    private static function validEntry(mixed $entry): array
    {
        if (! is_array($entry) || ! array_key_exists(self::KEY_SELECTOR, $entry)) {
            throw self::reject('each placement must be an object with a selector');
        }

        $selector = self::validSelector($entry[self::KEY_SELECTOR]);

        $position = $entry[self::KEY_POSITION] ?? self::POSITION_DEFAULT;
        if (! is_string($position) || ! in_array($position, self::POSITIONS, true)) {
            throw self::reject('position must be one of: '.implode(', ', self::POSITIONS));
        }

        return [$selector, $position];
    }

    /** A trimmed CSS selector within the allow-list + length cap; only ever a querySelector arg. */
    private static function validSelector(mixed $value): string
    {
        if (! is_string($value)) {
            throw self::reject('selector must be a string');
        }

        $selector = trim($value);

        if ($selector === '') {
            throw self::reject('selector must not be blank');
        }

        if (mb_strlen($selector) > self::SELECTOR_MAX) {
            throw self::reject('selector must be at most '.self::SELECTOR_MAX.' characters');
        }

        if (preg_match(self::SELECTOR_PATTERN, $selector) !== 1) {
            throw self::reject('selector contains characters that are not valid in a CSS selector');
        }

        return $selector;
    }

    private static function reject(string $detail): InvalidBannerException
    {
        return InvalidBannerException::make('placements', InvalidBannerException::REASON_INVALID_PLACEMENTS, $detail);
    }
}
