<?php

namespace App\Domain\Sites;

/**
 * StoreCategory — the per-site "store type" that selects a tailored try-on prompt.
 *
 * The site stores one of these keys in sites.product_category; GenerateTryOnJob feeds it
 * to AiOperationResolver as the product_type leg, so the matching product_type-scoped
 * prompt (seeded per category, admin-editable) wins over the generic global one. Each
 * category also carries a SENSIBLE default for "ask the shopper's height" — clothing /
 * footwear need it; jewelry, furniture, eyewear, etc. do not.
 */
final class StoreCategory
{
    // === CONSTANTS ===
    public const GENERAL = 'general';
    public const JEWELRY = 'jewelry';
    public const CLOTHING = 'clothing';
    public const FOOTWEAR = 'footwear';
    public const EYEWEAR = 'eyewear';
    public const ACCESSORIES = 'accessories';
    public const FURNITURE = 'furniture';
    public const HOME_DECOR = 'home_decor';

    public const ALL = [
        self::GENERAL,
        self::JEWELRY,
        self::CLOTHING,
        self::FOOTWEAR,
        self::EYEWEAR,
        self::ACCESSORIES,
        self::FURNITURE,
        self::HOME_DECOR,
    ];

    // Category => whether the popup should ask the shopper's height by default.
    private const ASKS_HEIGHT = [
        self::GENERAL => true,
        self::JEWELRY => false,
        self::CLOTHING => true,
        self::FOOTWEAR => true,
        self::EYEWEAR => false,
        self::ACCESSORIES => false,
        self::FURNITURE => false,
        self::HOME_DECOR => false,
    ];

    private const LABEL_PREFIX = 'store_category.';

    public static function isValid(?string $key): bool
    {
        return $key !== null && in_array($key, self::ALL, true);
    }

    /** The recommended "ask for height" default for a category (true when unknown). */
    public static function asksHeight(?string $key): bool
    {
        return self::ASKS_HEIGHT[$key] ?? true;
    }

    /** category key => localised label, for a Filament Select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::ALL as $key) {
            $options[$key] = __(self::LABEL_PREFIX.$key);
        }

        return $options;
    }
}
