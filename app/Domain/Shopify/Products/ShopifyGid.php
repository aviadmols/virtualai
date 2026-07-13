<?php

namespace App\Domain\Shopify\Products;

/**
 * ShopifyGid — the Admin API's global object id, as a value helper.
 *
 * The Admin GraphQL API speaks GIDs ("gid://shopify/Product/123"); webhook payloads
 * (the REST-shaped push) speak bare numeric ids ("123"). One place converts between
 * them so no caller ever string-concatenates a gid by hand.
 */
final class ShopifyGid
{
    // === CONSTANTS ===
    public const TYPE_PRODUCT = 'Product';

    public const TYPE_VARIANT = 'ProductVariant';

    private const PREFIX = 'gid://shopify/';

    /** "gid://shopify/Product/123" for a numeric/string id (a gid passes through). */
    public static function for(string $type, int|string $id): string
    {
        $id = (string) $id;

        if (str_starts_with($id, self::PREFIX)) {
            return $id;
        }

        return self::PREFIX.$type.'/'.$id;
    }

    /** The numeric tail of a gid ("gid://shopify/Product/123" -> "123"), else null. */
    public static function id(?string $gid): ?string
    {
        if ($gid === null || $gid === '') {
            return null;
        }

        $tail = substr($gid, (int) strrpos($gid, '/') + 1);

        return $tail === '' ? null : $tail;
    }

    /** True when the string is a well-formed gid of the given type. */
    public static function isType(string $gid, string $type): bool
    {
        return str_starts_with($gid, self::PREFIX.$type.'/');
    }
}
