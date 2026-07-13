<?php

namespace App\Domain\Media;

use RuntimeException;

/**
 * MediaWriteException — the bytes did NOT land on the media disk.
 *
 * THE SCAR THIS TYPE EXISTS TO PREVENT: a write ATTEMPTED is not a write VERIFIED. Every disk in
 * config/filesystems.php is configured `throw => false`, so a failed S3/volume put() does not
 * raise — it returns FALSE. Code that ignored that boolean handed back a path pointing at
 * NOTHING, and every caller downstream believed it:
 *
 *   - the Shopify snapshot was stamped CAPTURED, the original was deleted from the live store,
 *     and the undo could not put it back — the original was gone from Shopify AND from us;
 *   - a try-on / banner / product-image charge would be written for a result the shopper or the
 *     merchant can never see.
 *
 * So MediaStorage never returns a StoredMedia it has not verified: the put() boolean is checked
 * AND the object is read back — and the readback must report EXACTLY the number of bytes we
 * handed the disk. Anything else throws THIS, and the money rails (which already wrap the store
 * in a try/catch) release the hold and write no charge row.
 *
 * THE SECOND GENERATION OF THE SAME SCAR: the readback existed, and it verified the WRONG
 * PREDICATE — "is the object at least 1 byte?" instead of "is it OUR bytes?". A SHORT write (the
 * local/volume driver's file_put_contents on a full disk returns a byte COUNT, not false) sails
 * through "at least 1 byte" and leaves a truncated object that licensed the deletion of a live
 * original. A verification that cannot distinguish OUR bytes from SOME bytes is not a
 * verification.
 */
final class MediaWriteException extends RuntimeException
{
    // === CONSTANTS ===
    public const CODE_WRITE_REJECTED = 'media_write_rejected';   // the disk refused the put()

    public const CODE_WRITE_UNVERIFIED = 'media_write_unverified'; // put() said yes; the readback disagrees

    private const MSG_REJECTED = 'The media disk refused to write %s (%d bytes).';

    private const MSG_UNVERIFIED = 'The media disk accepted %s but stored %d of %d bytes (a short, empty or missing object).';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function rejected(string $path, int $byteSize): self
    {
        return new self(self::CODE_WRITE_REJECTED, sprintf(self::MSG_REJECTED, $path, $byteSize));
    }

    /** The object that came back is not the object we wrote: truncated, empty, or absent. */
    public static function unverified(string $path, int $expected, int $stored): self
    {
        return new self(self::CODE_WRITE_UNVERIFIED, sprintf(self::MSG_UNVERIFIED, $path, $stored, $expected));
    }
}
