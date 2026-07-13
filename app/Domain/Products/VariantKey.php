<?php

namespace App\Domain\Products;

use App\Models\ProductVariant;

/**
 * VariantKey — the ONE stable identity of a variant across a re-sync / re-scan.
 *
 * Shopify supplies a real variant GID, so it is the key. A scanned variant has no
 * platform id, so its option map ({color: Red, size: M}, order-insensitive) is the key.
 * Both rails therefore UPSERT the same row instead of delete-and-recreate, which is
 * what keeps `generations.product_variant_id` (and the gallery entries pointing at it)
 * valid across a catalog refresh.
 */
final class VariantKey
{
    // === CONSTANTS ===
    private const PREFIX_EXTERNAL = 'ext:';

    private const PREFIX_OPTIONS = 'opt:';

    /** The key of an incoming (mapped) variant row. */
    public static function forRow(array $row): string
    {
        $external = $row['external_id'] ?? null;

        if (is_string($external) && trim($external) !== '') {
            return self::PREFIX_EXTERNAL.trim($external);
        }

        return self::PREFIX_OPTIONS.self::optionsHash($row['options'] ?? []);
    }

    /** The key of a persisted variant row (must match forRow() exactly). */
    public static function forModel(ProductVariant $variant): string
    {
        $external = $variant->external_id;

        if (is_string($external) && trim($external) !== '') {
            return self::PREFIX_EXTERNAL.trim($external);
        }

        return self::PREFIX_OPTIONS.self::optionsHash($variant->options ?? []);
    }

    /** Order-insensitive hash of an {axis => value} map. */
    private static function optionsHash(mixed $options): string
    {
        $options = is_array($options) ? $options : [];

        ksort($options);

        return sha1((string) json_encode($options));
    }
}
