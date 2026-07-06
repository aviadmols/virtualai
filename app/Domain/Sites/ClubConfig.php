<?php

namespace App\Domain\Sites;

/**
 * ClubConfig — the per-site Customer-Club schema (single source of truth), mirroring
 * WidgetAppearance. Defines the configurable fields (enabled, member discount percent,
 * per-surface price-zone selectors) with their allowed values + DEFAULTS. Three
 * consumers share it:
 *  - SiteSettingsService::validate() → sanitize() (reject bad values before persisting),
 *  - BootstrapController → resolve() (merge stored over defaults so the widget always
 *    receives a complete, valid config even when the merchant never customized),
 *  - the merchant Club-settings page (options + defaults for the form — a later agent).
 *
 * The discount is DISPLAY-ONLY (no checkout integration): the widget annotates the shown
 * price for a verified member. The price-zone selectors are config the widget hands to
 * querySelector — NEVER eval'd — so the SELECTOR_PATTERN allow-list (reused from
 * WidgetAppearance's shape) + a length + a per-surface count cap are the whole defence.
 */
final class ClubConfig
{
    // === CONSTANTS ===
    // Top-level field keys (the stored club_config JSON object's keys).
    public const KEY_ENABLED = 'enabled';

    public const KEY_DISCOUNT_PERCENT = 'discount_percent';

    public const KEY_PRICE_ZONES = 'price_zones';

    // The three storefront surfaces the merchant can pick price zones on.
    public const SURFACE_PDP = 'pdp';

    public const SURFACE_CATALOG = 'catalog';

    public const SURFACE_CART = 'cart';

    public const SURFACES = [
        self::SURFACE_PDP,
        self::SURFACE_CATALOG,
        self::SURFACE_CART,
    ];

    // Discount is a whole percent, 0..100 (display-only).
    public const DISCOUNT_MIN = 0;

    public const DISCOUNT_MAX = 100;

    // Selector guards (mirroring WidgetAppearance).
    public const SELECTOR_MAX = 500;

    // At most this many price-zone selectors per surface (a merchant needs a few, not many).
    public const ZONES_PER_SURFACE_MAX = 5;

    // A stored selector is config the widget hands to querySelector — NEVER eval'd. Allow
    // only characters that appear in real CSS selectors (incl. the `>` child combinator);
    // reject anything scriptable ({ } < ; / ` are all excluded, so `/*`, `<script`, and
    // declaration blocks can't appear). Identical allow-list to WidgetAppearance.
    private const SELECTOR_PATTERN = '/^[\w #.>~+*^$|:,()\[\]="\'-]+$/';

    // The locked defaults — club OFF, no discount, no zones on any surface.
    public const DEFAULTS = [
        self::KEY_ENABLED => false,
        self::KEY_DISCOUNT_PERCENT => 0,
        self::KEY_PRICE_ZONES => [
            self::SURFACE_PDP => [],
            self::SURFACE_CATALOG => [],
            self::SURFACE_CART => [],
        ],
    ];

    /** The complete default club config. */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The effective club config for the widget: stored values merged OVER the defaults,
     * keeping only known keys. Always returns a complete, valid set (enabled bool,
     * discount int, price_zones with all three surfaces present as arrays).
     *
     * @param  array<string,mixed>|null  $stored
     * @return array<string,mixed>
     */
    public static function resolve(?array $stored): array
    {
        $resolved = self::DEFAULTS;

        if (! is_array($stored)) {
            return $resolved;
        }

        if (array_key_exists(self::KEY_ENABLED, $stored) && $stored[self::KEY_ENABLED] !== null) {
            $resolved[self::KEY_ENABLED] = (bool) $stored[self::KEY_ENABLED];
        }

        if (array_key_exists(self::KEY_DISCOUNT_PERCENT, $stored) && is_int($stored[self::KEY_DISCOUNT_PERCENT])) {
            $resolved[self::KEY_DISCOUNT_PERCENT] = $stored[self::KEY_DISCOUNT_PERCENT];
        }

        // Price zones: keep only the three known surfaces, each a list of strings.
        if (isset($stored[self::KEY_PRICE_ZONES]) && is_array($stored[self::KEY_PRICE_ZONES])) {
            foreach (self::SURFACES as $surface) {
                $zones = $stored[self::KEY_PRICE_ZONES][$surface] ?? null;
                if (is_array($zones)) {
                    $resolved[self::KEY_PRICE_ZONES][$surface] = array_values(array_filter(
                        $zones,
                        static fn ($z) => is_string($z) && $z !== '',
                    ));
                }
            }
        }

        return $resolved;
    }

    /**
     * Validate a merchant-submitted club config and return the sanitized full set
     * (defaults applied for any absent key). Throws InvalidSiteSettingsException on any
     * bad value — nothing is persisted.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array
    {
        $clean = self::DEFAULTS;

        if (array_key_exists(self::KEY_ENABLED, $input)) {
            $clean[self::KEY_ENABLED] = (bool) $input[self::KEY_ENABLED];
        }

        if (array_key_exists(self::KEY_DISCOUNT_PERCENT, $input)) {
            $clean[self::KEY_DISCOUNT_PERCENT] = self::validDiscount($input[self::KEY_DISCOUNT_PERCENT]);
        }

        if (array_key_exists(self::KEY_PRICE_ZONES, $input)) {
            $clean[self::KEY_PRICE_ZONES] = self::validPriceZones($input[self::KEY_PRICE_ZONES]);
        }

        return $clean;
    }

    /** A whole percent 0..100. Non-ints / out-of-range are rejected. */
    private static function validDiscount(mixed $value): int
    {
        if (! is_int($value) || $value < self::DISCOUNT_MIN || $value > self::DISCOUNT_MAX) {
            throw InvalidSiteSettingsException::club(
                self::KEY_DISCOUNT_PERCENT,
                'must be a whole number between '.self::DISCOUNT_MIN.' and '.self::DISCOUNT_MAX,
            );
        }

        return $value;
    }

    /**
     * The price-zones object: an object keyed by the three known surfaces, each a list of
     * validated CSS selectors (≤ ZONES_PER_SURFACE_MAX per surface). Unknown surfaces are
     * rejected; absent surfaces default to an empty list.
     *
     * @return array<string,array<int,string>>
     */
    private static function validPriceZones(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw InvalidSiteSettingsException::club(self::KEY_PRICE_ZONES, 'must be an object keyed by surface');
        }

        // Reject any surface key that is not one of the three known ones.
        foreach (array_keys($value) as $surface) {
            if (! in_array($surface, self::SURFACES, true)) {
                throw InvalidSiteSettingsException::club(self::KEY_PRICE_ZONES, 'has an unknown surface "'.$surface.'"');
            }
        }

        $zones = self::DEFAULTS[self::KEY_PRICE_ZONES];

        foreach (self::SURFACES as $surface) {
            if (! array_key_exists($surface, $value)) {
                continue; // absent surface -> empty default
            }

            $zones[$surface] = self::validSurfaceSelectors($surface, $value[$surface]);
        }

        return $zones;
    }

    /**
     * One surface's selector list: a list (not an object) of ≤ ZONES_PER_SURFACE_MAX
     * validated CSS selectors. Each selector runs through the same allow-list as the
     * widget-appearance anchor. Blank entries are dropped; a too-long list is rejected.
     *
     * @return array<int,string>
     */
    private static function validSurfaceSelectors(string $surface, mixed $list): array
    {
        if (! is_array($list) || (! array_is_list($list) && $list !== [])) {
            throw InvalidSiteSettingsException::club(self::KEY_PRICE_ZONES, $surface.' zones must be a list of selectors');
        }

        $clean = [];

        foreach ($list as $raw) {
            $selector = self::validSelector($surface, $raw);
            if ($selector !== '') {
                $clean[] = $selector;
            }
        }

        if (count($clean) > self::ZONES_PER_SURFACE_MAX) {
            throw InvalidSiteSettingsException::club(
                self::KEY_PRICE_ZONES,
                $surface.' has too many zones (max '.self::ZONES_PER_SURFACE_MAX.')',
            );
        }

        return $clean;
    }

    /**
     * A price-zone CSS selector: a trimmed string, ≤ SELECTOR_MAX chars, restricted to
     * characters that appear in real CSS selectors (SELECTOR_PATTERN). Only ever passed to
     * the browser's querySelector — never evaluated — so the allow-list is the whole
     * defence. A blank value returns '' (dropped by the caller); a bad value throws.
     */
    private static function validSelector(string $surface, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            throw InvalidSiteSettingsException::club(self::KEY_PRICE_ZONES, $surface.' selectors must be strings');
        }

        $selector = trim($value);

        if ($selector === '') {
            return '';
        }

        if (mb_strlen($selector) > self::SELECTOR_MAX) {
            throw InvalidSiteSettingsException::club(
                self::KEY_PRICE_ZONES,
                $surface.' selector must be at most '.self::SELECTOR_MAX.' characters',
            );
        }

        if (preg_match(self::SELECTOR_PATTERN, $selector) !== 1) {
            throw InvalidSiteSettingsException::club(
                self::KEY_PRICE_ZONES,
                $surface.' selector contains characters that are not valid in a CSS selector',
            );
        }

        return $selector;
    }
}
