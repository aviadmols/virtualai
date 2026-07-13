<?php

namespace App\Domain\ProductImages;

use App\Models\Product;
use App\Models\ProductImageBatch;

/**
 * SourceImagePicker — which of a product's OWN photos feeds the transform.
 *
 * The merchant picks the slot once for the whole batch (main image, or the 1st/2nd/3rd
 * additional image). A product that has nothing in that slot is SKIPPED — never silently
 * transformed from a different photo, because the source image is part of the asset's
 * identity (its hash is a segment of the idempotency key) and swapping it behind the
 * merchant's back would produce an image they did not ask for and still charge for it.
 */
final class SourceImagePicker
{
    // === CONSTANTS ===
    // The 0-based index into products.images each ALT pick reads.
    private const ALT_INDEX = [
        ProductImageBatch::SOURCE_ALT_1 => 0,
        ProductImageBatch::SOURCE_ALT_2 => 1,
        ProductImageBatch::SOURCE_ALT_3 => 2,
    ];

    // An images[] entry may be a bare url string or a {url: ...} bag (both shapes exist
    // across the scan and Shopify mappers).
    private const URL_KEY = 'url';

    private const HTTP_PREFIXES = ['http://', 'https://'];

    /** The source photo url for this product + pick, or null when the product has none. */
    public static function urlFor(Product $product, string $pick): ?string
    {
        $url = $pick === ProductImageBatch::SOURCE_MAIN
            ? $product->main_image_url
            : self::altUrl($product, $pick);

        return self::usable($url) ? (string) $url : null;
    }

    /** The stable hash of a source photo — a segment of the asset idempotency key. */
    public static function hash(string $url): string
    {
        return sha1($url);
    }

    /** The Nth additional image (products.images), or null when the slot is empty. */
    private static function altUrl(Product $product, string $pick): ?string
    {
        $index = self::ALT_INDEX[$pick] ?? null;

        if ($index === null) {
            return null;
        }

        $images = array_values(is_array($product->images) ? $product->images : []);
        $entry = $images[$index] ?? null;

        if (is_array($entry)) {
            $entry = $entry[self::URL_KEY] ?? null;
        }

        return is_string($entry) ? $entry : null;
    }

    /** Only a real http(s) url is usable: the provider must be able to fetch it. */
    private static function usable(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        foreach (self::HTTP_PREFIXES as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
