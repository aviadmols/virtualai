<?php

namespace App\Domain\Shopify\Products;

/**
 * ShopifyProductQueries — every Admin GraphQL document the product rail sends, in ONE
 * place (CONST-at-top). A query is never assembled inside a service, and no caller
 * interpolates a value into a document — everything rides as a typed $variable, so a
 * merchant-supplied search term can never become GraphQL.
 *
 * The shared PRODUCT_FIELDS fragment is what ShopifyProductMapper maps 1:1; changing a
 * field here without changing the mapper is the only way they can drift, so they live
 * next to each other.
 */
final class ShopifyProductQueries
{
    // === CONSTANTS ===
    // How many variants/images a single product carries into the mapper. Shopify's own
    // ceiling for `variants(first:)` is 250; 100 covers the overwhelming majority of
    // apparel/jewelry catalogs and keeps the query cost (and throttle burn) modest.
    public const VARIANTS_PER_PRODUCT = 100;

    public const IMAGES_PER_PRODUCT = 10;

    // Collections a product belongs to — the input to a collection-scoped button rule. A
    // product rarely sits in many collections; 25 is ample and keeps the query cost modest.
    public const COLLECTIONS_PER_PRODUCT = 25;

    // Product metafields (the merchant's own custom fields) — the pool the try-on prompt editor
    // offers as {{mf_*}} tokens. 30 covers a rich custom-data setup without bloating the query.
    public const METAFIELDS_PER_PRODUCT = 30;

    // The one field set both the catalog walk and the single-product fetch select.
    private const PRODUCT_FIELDS = <<<'GRAPHQL'
    fragment ProductFields on Product {
      id
      handle
      title
      description
      descriptionHtml
      productType
      vendor
      tags
      collections(first: COLLECTIONS_LIMIT) { nodes { handle title } }
      metafields(first: METAFIELDS_LIMIT) { nodes { namespace key type value } }
      status
      onlineStoreUrl
      featuredImage { url altText }
      images(first: IMAGES_LIMIT) { nodes { url altText } }
      options { name position values }
      priceRangeV2 {
        minVariantPrice { amount currencyCode }
        maxVariantPrice { amount currencyCode }
      }
      variants(first: VARIANTS_LIMIT) {
        nodes {
          id
          title
          sku
          position
          availableForSale
          price
          compareAtPrice
          selectedOptions { name value }
          image { url }
        }
      }
    }
    GRAPHQL;

    /** One page of the catalog walk (cursor-paginated, newest-agnostic, stable order). */
    public static function catalogPage(): string
    {
        return self::withFragment(<<<'GRAPHQL'
        query TrayOnCatalogPage($first: Int!, $after: String, $query: String) {
          products(first: $first, after: $after, query: $query, sortKey: ID) {
            pageInfo { hasNextPage endCursor }
            nodes { ...ProductFields }
          }
        }
        GRAPHQL);
    }

    /** One product by GID (the sync-one job + the products/update webhook). */
    public static function singleProduct(): string
    {
        return self::withFragment(<<<'GRAPHQL'
        query TrayOnProduct($id: ID!) {
          product(id: $id) { ...ProductFields }
        }
        GRAPHQL);
    }

    /** The picker's live search — id/title/handle/thumb only (cheap, no variants). */
    public static function search(): string
    {
        return <<<'GRAPHQL'
        query TrayOnProductSearch($first: Int!, $query: String) {
          products(first: $first, query: $query, sortKey: TITLE) {
            nodes {
              id
              title
              handle
              status
              featuredImage { url }
            }
          }
        }
        GRAPHQL;
    }

    /** How many products the import-all cap is measured against. */
    public static function count(): string
    {
        return <<<'GRAPHQL'
        query TrayOnProductCount($query: String) {
          productsCount(query: $query) { count }
        }
        GRAPHQL;
    }

    /** Bind the shared fragment (with its limits) onto a document. */
    private static function withFragment(string $document): string
    {
        $fragment = strtr(self::PRODUCT_FIELDS, [
            'VARIANTS_LIMIT' => (string) self::VARIANTS_PER_PRODUCT,
            'IMAGES_LIMIT' => (string) self::IMAGES_PER_PRODUCT,
            'COLLECTIONS_LIMIT' => (string) self::COLLECTIONS_PER_PRODUCT,
            'METAFIELDS_LIMIT' => (string) self::METAFIELDS_PER_PRODUCT,
        ]);

        return $document."\n".$fragment;
    }
}
