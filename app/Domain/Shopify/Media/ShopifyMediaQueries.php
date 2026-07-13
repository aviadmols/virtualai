<?php

namespace App\Domain\Shopify\Media;

/**
 * ShopifyMediaQueries — every Admin GraphQL document the MEDIA rail sends, in ONE place
 * (CONST-at-top). A document is never assembled inside a service and no caller interpolates a
 * value into one: everything rides as a typed $variable, so a merchant-supplied alt text or a
 * media id can never become GraphQL.
 *
 * The four mutations, in the exact order the pusher runs them:
 *   1. stagedUploadsCreate  — a one-time upload target; OUR bucket stays PRIVATE, we hand
 *                             Shopify the BYTES, never a public URL of ours.
 *   2. productCreateMedia   — attach the uploaded resource to the product. Shopify processes it
 *                             ASYNCHRONOUSLY, so the media comes back UPLOADED, not READY.
 *   3. productReorderMedia  — the placement (append is the natural result of 2; position N and
 *                             replace both move the new media into a slot).
 *   4. productDeleteMedia   — ONLY for a replace, and ONLY after the replacement is READY.
 *
 * productCreateMedia is marked deprecated on the newest Admin API in favour of
 * productUpdate/productSet, but it remains supported on the pinned version and is the mutation
 * that returns `mediaUserErrors` verbatim (which the merchant must see). When the pinned
 * version is bumped past its removal, this class is the ONE place to change.
 */
final class ShopifyMediaQueries
{
    // === CONSTANTS ===
    // The MediaImage subset we read: id + processing status + alt + the CDN url (the bytes we
    // download to snapshot the original gallery).
    private const MEDIA_FIELDS = <<<'GRAPHQL'
    fragment MediaFields on Media {
      id
      status
      alt
      mediaContentType
      ... on MediaImage {
        image { url width height }
      }
    }
    GRAPHQL;

    /** A one-time staged upload target for ONE image (resource: PRODUCT_IMAGE, POST multipart). */
    public static function stagedUploadsCreate(): string
    {
        return <<<'GRAPHQL'
        mutation TrayOnStagedUpload($input: [StagedUploadInput!]!) {
          stagedUploadsCreate(input: $input) {
            stagedTargets {
              url
              resourceUrl
              parameters { name value }
            }
            userErrors { field message }
          }
        }
        GRAPHQL;
    }

    /** Attach the uploaded resource to the product. Returns the media (UPLOADED, not yet READY). */
    public static function productCreateMedia(): string
    {
        return self::withFragment(<<<'GRAPHQL'
        mutation TrayOnCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
          productCreateMedia(productId: $productId, media: $media) {
            media { ...MediaFields }
            mediaUserErrors { field message code }
          }
        }
        GRAPHQL);
    }

    /**
     * The product's CURRENT gallery, in order — the READY poll, the snapshot, the chooser.
     *
     * PAGINATED, and `pageInfo` is NOT optional. A product may hold up to 250 media while one
     * page returns far fewer: a gallery read that silently stopped at the page size produced a
     * snapshot that looked complete, was stamped CAPTURED, and licensed a destructive push whose
     * undo could never restore the media it never saw. The cursor is read; the caller walks every
     * page and REFUSES the push if it cannot read the gallery to its end (fail closed).
     */
    public static function productMedia(): string
    {
        return self::withFragment(<<<'GRAPHQL'
        query TrayOnProductMedia($id: ID!, $first: Int!, $after: String) {
          product(id: $id) {
            id
            media(first: $first, after: $after) {
              nodes { ...MediaFields }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL);
    }

    /** Move media into position. Shopify's newPosition is ZERO-based (slot 1 = newPosition 0). */
    public static function productReorderMedia(): string
    {
        return <<<'GRAPHQL'
        mutation TrayOnReorderMedia($id: ID!, $moves: [MoveInput!]!) {
          productReorderMedia(id: $id, moves: $moves) {
            job { id done }
            mediaUserErrors { field message code }
          }
        }
        GRAPHQL;
    }

    /** Remove media from a product. Called ONLY after its replacement is confirmed READY. */
    public static function productDeleteMedia(): string
    {
        return <<<'GRAPHQL'
        mutation TrayOnDeleteMedia($productId: ID!, $mediaIds: [ID!]!) {
          productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
            deletedMediaIds
            mediaUserErrors { field message code }
          }
        }
        GRAPHQL;
    }

    /** Bind the shared media fragment onto a document. */
    private static function withFragment(string $document): string
    {
        return $document."\n".self::MEDIA_FIELDS;
    }
}
