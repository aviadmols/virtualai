<?php

namespace App\Domain\Sites;

/**
 * WidgetAppearance — the per-site storefront-widget look schema (single source of truth).
 *
 * Defines the configurable fields (button placement + label + colors, popup theme +
 * accent), their allowed values, and their DEFAULTS. Three consumers share it:
 *  - SiteSettingsService::validate() → sanitize() (reject bad values before persisting),
 *  - BootstrapController → resolve() (merge stored over defaults so the widget always
 *    receives a complete, valid appearance even when the merchant never customized),
 *  - the merchant appearance page (options + defaults for the form).
 *
 * Keeping the schema here means the widget, the API and the admin form can never drift.
 */
final class WidgetAppearance
{
    // === CONSTANTS ===
    // Field keys (the stored widget_appearance JSON object's keys).
    public const KEY_PLACEMENT = 'button_placement';
    public const KEY_LABEL = 'button_label';
    public const KEY_BUTTON_BG = 'button_bg';
    public const KEY_BUTTON_TEXT = 'button_text_color';
    public const KEY_POPUP_THEME = 'popup_theme';
    public const KEY_POPUP_ACCENT = 'popup_accent';
    // Whether the popup asks the shopper for their height (cm). Off for jewelry / furniture
    // / eyewear where height is irrelevant; on for clothing / footwear.
    public const KEY_ASK_HEIGHT = 'ask_height';

    // Button placement relative to the store's add-to-cart (or a fixed screen corner), plus
    // CUSTOM — a merchant-picked host anchor (visual placement picker). The legacy enum values
    // stay for back-compat and are the runtime FALLBACK when a custom anchor no longer resolves.
    public const PLACEMENT_AFTER_ATC = 'after_add_to_cart';
    public const PLACEMENT_BEFORE_ATC = 'before_add_to_cart';
    public const PLACEMENT_FIXED_BR = 'fixed_bottom_right';
    public const PLACEMENT_FIXED_BL = 'fixed_bottom_left';
    public const PLACEMENT_CUSTOM = 'custom';

    public const PLACEMENTS = [
        self::PLACEMENT_AFTER_ATC,
        self::PLACEMENT_BEFORE_ATC,
        self::PLACEMENT_FIXED_BR,
        self::PLACEMENT_FIXED_BL,
        self::PLACEMENT_CUSTOM,
    ];

    // Custom-placement fields — meaningful only when KEY_PLACEMENT === PLACEMENT_CUSTOM.
    // custom_anchor_selector: the host CSS selector the merchant picked visually.
    // custom_position: where the button sits relative to that anchor.
    public const KEY_CUSTOM_ANCHOR = 'custom_anchor_selector';
    public const KEY_CUSTOM_POSITION = 'custom_position';

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

    public const CUSTOM_SELECTOR_MAX = 500;

    // A stored anchor selector is config the widget hands to querySelector — NEVER eval'd. Allow
    // only characters that appear in real CSS selectors (incl. the `>` child combinator); reject
    // anything scriptable so a bad/hostile value can neither break the widget nor smuggle markup
    // ({ } < ; / ` are all excluded, so `/*`, `<script`, and declaration blocks can't appear).
    private const SELECTOR_PATTERN = '/^[\w #.>~+*^$|:,()\[\]="\'-]+$/';

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';
    public const THEMES = [self::THEME_LIGHT, self::THEME_DARK];

    public const LABEL_MAX = 40;

    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    // The locked defaults — a brand-neutral monochrome button + light popup. The
    // ask_height default here (true) is the general fallback; defaultsForCategory()
    // overrides it per store type (jewelry/furniture/eyewear ask no height).
    public const DEFAULTS = [
        self::KEY_PLACEMENT => self::PLACEMENT_AFTER_ATC,
        self::KEY_LABEL => 'Try it on',
        self::KEY_BUTTON_BG => '#111111',
        self::KEY_BUTTON_TEXT => '#ffffff',
        self::KEY_POPUP_THEME => self::THEME_LIGHT,
        self::KEY_POPUP_ACCENT => '#111111',
        self::KEY_ASK_HEIGHT => true,
        self::KEY_CUSTOM_ANCHOR => '',
        self::KEY_CUSTOM_POSITION => self::POSITION_AFTER,
    ];

    /** The complete default appearance, with ask_height following the store category. */
    public static function defaults(?string $category = null): array
    {
        return self::defaultsForCategory($category);
    }

    /**
     * The defaults for a site whose store type is $category. Identical to DEFAULTS
     * except ask_height, whose sensible default follows the category (StoreCategory):
     * clothing/footwear ask the shopper's height, jewelry/furniture/eyewear do not.
     * A null/unknown category keeps the general default (ask height).
     *
     * @return array<string,mixed>
     */
    public static function defaultsForCategory(?string $category): array
    {
        $defaults = self::DEFAULTS;
        $defaults[self::KEY_ASK_HEIGHT] = StoreCategory::asksHeight($category);

        return $defaults;
    }

    /**
     * The effective appearance for the widget: stored values merged OVER the defaults,
     * keeping only known keys. Always returns a complete, valid set. When the merchant
     * never set ask_height, it defaults from the site's store category ($category); an
     * explicit stored value always wins.
     *
     * @param  array<string,mixed>|null  $stored
     * @return array<string,mixed>
     */
    public static function resolve(?array $stored, ?string $category = null): array
    {
        $resolved = self::defaultsForCategory($category);

        foreach ($resolved as $key => $default) {
            if (is_array($stored) && array_key_exists($key, $stored) && $stored[$key] !== null && $stored[$key] !== '') {
                $resolved[$key] = $stored[$key];
            }
        }

        return $resolved;
    }

    /**
     * Validate a merchant-submitted appearance object and return the sanitized full set
     * (defaults applied for any absent key). Throws InvalidSiteSettingsException on any
     * bad value — nothing is persisted.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array
    {
        $clean = self::DEFAULTS;

        if (array_key_exists(self::KEY_PLACEMENT, $input)) {
            $clean[self::KEY_PLACEMENT] = self::validEnum(self::KEY_PLACEMENT, $input[self::KEY_PLACEMENT], self::PLACEMENTS);
        }

        if (array_key_exists(self::KEY_POPUP_THEME, $input)) {
            $clean[self::KEY_POPUP_THEME] = self::validEnum(self::KEY_POPUP_THEME, $input[self::KEY_POPUP_THEME], self::THEMES);
        }

        if (array_key_exists(self::KEY_ASK_HEIGHT, $input)) {
            $clean[self::KEY_ASK_HEIGHT] = (bool) $input[self::KEY_ASK_HEIGHT];
        }

        // The two custom fields are only meaningful for custom placement; a null/empty value
        // (e.g. a Hidden form field dehydrating '' to null) keeps the default, never throws.
        if (array_key_exists(self::KEY_CUSTOM_POSITION, $input) && $input[self::KEY_CUSTOM_POSITION] !== null && $input[self::KEY_CUSTOM_POSITION] !== '') {
            $clean[self::KEY_CUSTOM_POSITION] = self::validEnum(self::KEY_CUSTOM_POSITION, $input[self::KEY_CUSTOM_POSITION], self::POSITIONS);
        }

        if (array_key_exists(self::KEY_CUSTOM_ANCHOR, $input)) {
            $clean[self::KEY_CUSTOM_ANCHOR] = self::validSelector($input[self::KEY_CUSTOM_ANCHOR]);
        }

        if (array_key_exists(self::KEY_LABEL, $input)) {
            $clean[self::KEY_LABEL] = self::validLabel($input[self::KEY_LABEL]);
        }

        foreach ([self::KEY_BUTTON_BG, self::KEY_BUTTON_TEXT, self::KEY_POPUP_ACCENT] as $colorKey) {
            if (array_key_exists($colorKey, $input)) {
                $clean[$colorKey] = self::validHex($colorKey, $input[$colorKey]);
            }
        }

        // Custom placement is meaningless without a concrete anchor to place against.
        if ($clean[self::KEY_PLACEMENT] === self::PLACEMENT_CUSTOM && $clean[self::KEY_CUSTOM_ANCHOR] === '') {
            throw InvalidSiteSettingsException::appearance(self::KEY_CUSTOM_ANCHOR, 'is required when the placement is custom');
        }

        return $clean;
    }

    private static function validEnum(string $field, mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw InvalidSiteSettingsException::appearance($field, 'must be one of: '.implode(', ', $allowed));
        }

        return $value;
    }

    private static function validLabel(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::LABEL_MAX) {
            throw InvalidSiteSettingsException::appearance(self::KEY_LABEL, 'must be 1–'.self::LABEL_MAX.' characters');
        }

        return trim($value);
    }

    private static function validHex(string $field, mixed $value): string
    {
        if (! is_string($value) || preg_match(self::HEX_PATTERN, $value) !== 1) {
            throw InvalidSiteSettingsException::appearance($field, 'must be a #RRGGBB hex colour');
        }

        return strtolower($value);
    }

    /**
     * A custom-placement anchor selector: a trimmed CSS selector, ≤ CUSTOM_SELECTOR_MAX chars,
     * restricted to characters that appear in real CSS selectors (SELECTOR_PATTERN). An empty
     * value is allowed here (the runtime falls back); the "required when custom" rule is enforced
     * in sanitize() against the resolved placement. The value is only ever passed to the browser's
     * querySelector — never evaluated — so the allow-list is the whole defence.
     */
    private static function validSelector(mixed $value): string
    {
        // Null/empty are allowed (kept as the empty default); the "required when custom" rule is
        // enforced separately in sanitize(). A Hidden form field dehydrates '' to null, so tolerate it.
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            throw InvalidSiteSettingsException::appearance(self::KEY_CUSTOM_ANCHOR, 'must be a CSS selector string');
        }

        $selector = trim($value);

        if ($selector === '') {
            return '';
        }

        if (mb_strlen($selector) > self::CUSTOM_SELECTOR_MAX) {
            throw InvalidSiteSettingsException::appearance(self::KEY_CUSTOM_ANCHOR, 'must be at most '.self::CUSTOM_SELECTOR_MAX.' characters');
        }

        if (preg_match(self::SELECTOR_PATTERN, $selector) !== 1) {
            throw InvalidSiteSettingsException::appearance(self::KEY_CUSTOM_ANCHOR, 'contains characters that are not valid in a CSS selector');
        }

        return $selector;
    }
}
