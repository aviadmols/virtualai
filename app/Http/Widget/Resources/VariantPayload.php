<?php

namespace App\Http\Widget\Resources;

use App\Domain\Shopify\Products\ShopifyGid;
use App\Models\ProductVariant;

/**
 * VariantPayload — the PUBLIC, secret-free shape of a product variant for the widget.
 * Shared by the bootstrap product (ProductPayload) and a past look (GenerationPayload),
 * so the widget's cart layer resolves the add-to-cart target the same way everywhere.
 */
final class VariantPayload
{
    public static function make(ProductVariant $variant): array
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
