<?php

namespace App\Http\Widget\Resources;

use App\Models\Product;

/**
 * ProductPayload — the PUBLIC, secret-free shape of a confirmed product + its variants
 * for the widget bootstrap. Only fields the storefront needs to render the modal +
 * drive variant selection. NEVER includes scan_raw / detected_selectors internals beyond
 * what the widget needs, and never any account/tenant secret.
 */
final class ProductPayload
{
    /** @param  \Illuminate\Support\Collection<int,\App\Models\ProductVariant>  $variants */
    public static function make(Product $product, iterable $variants): array
    {
        return [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'description' => $product->description,
            'product_type' => $product->product_type,
            'price_minor' => $product->price_minor,
            'currency' => $product->currency,
            'main_image_url' => $product->main_image_url,
            'images' => $product->images ?? [],
            'variants' => array_map(self::variant(...), iterator_to_array($variants)),
        ];
    }

    private static function variant(\App\Models\ProductVariant $variant): array
    {
        return [
            'id' => (int) $variant->getKey(),
            'options' => $variant->options ?? [],
            'price_minor' => $variant->price_minor,
            'image_url' => $variant->image_url,
            'sku' => $variant->sku,
            'available' => (bool) $variant->available,
        ];
    }
}
