<?php

namespace App\Http\Widget\Resources;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * ProductPayload — the PUBLIC, secret-free shape of a confirmed product + its variants
 * for the widget bootstrap. Only fields the storefront needs to render the modal +
 * drive variant selection. NEVER includes scan_raw / detected_selectors internals beyond
 * what the widget needs, and never any account/tenant secret.
 */
final class ProductPayload
{
    /** @param  Collection<int,ProductVariant>  $variants */
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
            // Which rail ingested this product (scan | shopify). The widget picks its
            // add-to-cart STRATEGY from this instead of guessing from the page: a Shopify
            // product gets the AJAX /cart/add.js path, a scanned one the selector path.
            // It is a category, not an identifier — it names no store and no account.
            'source' => $product->source,
            'variants' => array_map(
                static fn (ProductVariant $variant): array => VariantPayload::make($variant),
                iterator_to_array($variants),
            ),
        ];
    }
}
