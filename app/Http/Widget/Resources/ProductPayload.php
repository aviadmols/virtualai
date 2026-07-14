<?php

namespace App\Http\Widget\Resources;

use App\Domain\Shopify\Products\ShopifyGid;
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
            'variants' => array_map(self::variant(...), iterator_to_array($variants)),
        ];
    }

    private static function variant(ProductVariant $variant): array
    {
        return [
            'id' => (int) $variant->getKey(),
            // The NUMERIC Shopify variant id (the GID's tail), because `id` above is our
            // internal DB key and Shopify's /cart/add.js only speaks the numeric id.
            //
            // WHY THIS LEAKS NOTHING: the same number is already public on the merchant's own
            // PDP — it is the value of the <select>/<input name="id"> inside their
            // <form action="/cart/add"> and in every /products/x.js response. Anyone who can
            // reach this payload can already read it off the page it is rendered on. It
            // identifies a public SKU, never an account, a site, a shopper, or a cost.
            // Null for a scanned (non-Shopify) product — and null for anything that is not a
            // well-formed ProductVariant GID, so no stray internal value can ride out here.
            'external_id' => self::shopifyVariantId($variant),
            'options' => $variant->options ?? [],
            'price_minor' => $variant->price_minor,
            'image_url' => $variant->image_url,
            'sku' => $variant->sku,
            'available' => (bool) $variant->available,
        ];
    }

    /** "gid://shopify/ProductVariant/123" -> "123"; null for anything else. */
    private static function shopifyVariantId(ProductVariant $variant): ?string
    {
        $gid = (string) ($variant->external_id ?? '');

        if ($gid === '' || ! ShopifyGid::isType($gid, ShopifyGid::TYPE_VARIANT)) {
            return null;
        }

        return ShopifyGid::id($gid);
    }
}
