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

    // Button placement relative to the store's add-to-cart (or a fixed screen corner).
    public const PLACEMENT_AFTER_ATC = 'after_add_to_cart';
    public const PLACEMENT_BEFORE_ATC = 'before_add_to_cart';
    public const PLACEMENT_FIXED_BR = 'fixed_bottom_right';
    public const PLACEMENT_FIXED_BL = 'fixed_bottom_left';

    public const PLACEMENTS = [
        self::PLACEMENT_AFTER_ATC,
        self::PLACEMENT_BEFORE_ATC,
        self::PLACEMENT_FIXED_BR,
        self::PLACEMENT_FIXED_BL,
    ];

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';
    public const THEMES = [self::THEME_LIGHT, self::THEME_DARK];

    public const LABEL_MAX = 40;

    private const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    // The locked defaults — a brand-neutral monochrome button + light popup.
    public const DEFAULTS = [
        self::KEY_PLACEMENT => self::PLACEMENT_AFTER_ATC,
        self::KEY_LABEL => 'Try it on',
        self::KEY_BUTTON_BG => '#111111',
        self::KEY_BUTTON_TEXT => '#ffffff',
        self::KEY_POPUP_THEME => self::THEME_LIGHT,
        self::KEY_POPUP_ACCENT => '#111111',
        self::KEY_ASK_HEIGHT => true,
    ];

    /** The complete default appearance. */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The effective appearance for the widget: stored values merged OVER the defaults,
     * keeping only known keys. Always returns a complete, valid set.
     *
     * @param  array<string,mixed>|null  $stored
     * @return array<string,mixed>
     */
    public static function resolve(?array $stored): array
    {
        $resolved = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $default) {
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

        if (array_key_exists(self::KEY_LABEL, $input)) {
            $clean[self::KEY_LABEL] = self::validLabel($input[self::KEY_LABEL]);
        }

        foreach ([self::KEY_BUTTON_BG, self::KEY_BUTTON_TEXT, self::KEY_POPUP_ACCENT] as $colorKey) {
            if (array_key_exists($colorKey, $input)) {
                $clean[$colorKey] = self::validHex($colorKey, $input[$colorKey]);
            }
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
}
