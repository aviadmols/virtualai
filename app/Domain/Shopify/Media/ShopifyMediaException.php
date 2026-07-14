<?php

namespace App\Domain\Shopify\Media;

use RuntimeException;

/**
 * ShopifyMediaException — the TYPED failure of a media mutation.
 *
 * Shopify answers a media mutation with `mediaUserErrors` (a 200 with a business error, not an
 * HTTP failure). Those messages are the ONLY explanation the merchant will ever get for why
 * their image did not land — so they are carried VERBATIM, surfaced on the asset's push_error
 * and rendered plainly in the studio. They are never swallowed, never re-worded, never reduced
 * to "something went wrong".
 */
final class ShopifyMediaException extends RuntimeException
{
    // === CONSTANTS ===
    public const CODE_MEDIA_USER_ERROR = 'media_user_error';   // Shopify refused the mutation

    public const CODE_STAGED_UPLOAD = 'staged_upload_failed';  // could not obtain / use the target

    public const CODE_UPLOAD_FAILED = 'upload_failed';         // the byte transfer itself failed

    public const CODE_NOT_READY = 'media_not_ready';           // the poll budget ran out

    public const CODE_PROCESSING_FAILED = 'media_processing_failed'; // Shopify reported FAILED

    public const CODE_NO_MEDIA = 'media_missing';              // the mutation returned nothing

    public const CODE_GALLERY_UNREAD = 'gallery_unread';       // we could not read the WHOLE gallery

    public const CODE_DELETE_UNCONFIRMED = 'delete_unconfirmed'; // Shopify did not confirm the delete

    private const JOIN = ' | ';

    private const JOIN_IDS = ', ';

    /**
     * @param  array<int,string>  $errors  the verbatim mediaUserErrors messages
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<int,array<string,mixed>> $mediaUserErrors */
    public static function fromMediaUserErrors(array $mediaUserErrors): self
    {
        $messages = array_values(array_filter(array_map(
            static fn (mixed $error): string => (string) (((array) $error)['message'] ?? ''),
            $mediaUserErrors,
        )));

        return new self(
            self::CODE_MEDIA_USER_ERROR,
            $messages === [] ? 'Shopify refused the media mutation.' : implode(self::JOIN, $messages),
            $messages,
        );
    }

    public static function stagedUpload(string $reason): self
    {
        return new self(self::CODE_STAGED_UPLOAD, $reason, [$reason]);
    }

    public static function uploadFailed(int $status): self
    {
        $reason = sprintf('The staged upload of the image bytes failed (HTTP %d).', $status);

        return new self(self::CODE_UPLOAD_FAILED, $reason, [$reason]);
    }

    public static function notReady(string $mediaId, int $attempts): self
    {
        $reason = sprintf('Shopify did not finish processing media %s after %d checks.', $mediaId, $attempts);

        return new self(self::CODE_NOT_READY, $reason, [$reason]);
    }

    public static function processingFailed(string $mediaId): self
    {
        $reason = sprintf('Shopify reported media %s as FAILED during processing.', $mediaId);

        return new self(self::CODE_PROCESSING_FAILED, $reason, [$reason]);
    }

    public static function noMedia(): self
    {
        $reason = 'Shopify accepted the mutation but returned no media object.';

        return new self(self::CODE_NO_MEDIA, $reason, [$reason]);
    }

    /**
     * WE ASKED SHOPIFY TO DELETE MEDIA AND IT DID NOT SAY IT DID.
     *
     * productDeleteMedia answers with `deletedMediaIds`. Trusting the CALL instead of the ANSWER
     * meant an id Shopify silently kept was treated as gone: the asset link was cleared, and our
     * image stayed LIVE in the merchant's storefront with nothing pointing at it any more. A delete
     * is only real when the id we asked for comes back in the confirmation.
     *
     * @param  array<int,string>  $missing
     */
    public static function deleteNotConfirmed(array $missing): self
    {
        $reason = sprintf(
            'Shopify did not confirm the deletion of media %s; the store may still be showing them.',
            implode(self::JOIN_IDS, $missing),
        );

        return new self(self::CODE_DELETE_UNCONFIRMED, $reason, [$reason]);
    }

    /**
     * The gallery could not be read to its END (the cursor ran past the page budget, or Shopify
     * promised a next page and gave us no cursor). A PARTIAL gallery must never be treated as
     * the whole one: it would be snapshotted as complete and license a destructive push whose
     * undo could not restore what it never saw. FAIL CLOSED.
     */
    public static function galleryUnread(string $productGid, int $read): self
    {
        $reason = sprintf(
            'The product gallery of %s could not be read completely (%d media read, more remain); the push was refused.',
            $productGid,
            $read,
        );

        return new self(self::CODE_GALLERY_UNREAD, $reason, [$reason]);
    }
}
