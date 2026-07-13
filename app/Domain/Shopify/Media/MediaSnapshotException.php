<?php

namespace App\Domain\Shopify\Media;

use RuntimeException;

/**
 * MediaSnapshotException — we could not take (or could not honour) our own copy of a product's
 * ORIGINAL gallery.
 *
 * It exists as its OWN type because of what it must cause: a destructive push (replace, or a
 * reorder that moves the featured image) that cannot be snapshotted is REFUSED. Shopify drops
 * an image's bytes when its media is deleted, so an "Undo" we cannot honour is not a degraded
 * feature — it is a lie. FAIL CLOSED.
 */
final class MediaSnapshotException extends RuntimeException
{
    // === CONSTANTS ===
    public const CODE_CAPTURE_FAILED = 'snapshot_capture_failed';   // read/download/store failed

    public const CODE_NOT_CAPTURED = 'snapshot_not_captured';       // no usable snapshot exists

    public const CODE_NOT_RESTORABLE = 'snapshot_not_restorable';   // an original we cannot re-upload

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function captureFailed(int $productId, string $reason): self
    {
        return new self(self::CODE_CAPTURE_FAILED, sprintf(
            'Could not back up the original images of product #%d, so the push was refused: %s',
            $productId,
            $reason,
        ));
    }

    public static function notCaptured(int $productId): self
    {
        return new self(self::CODE_NOT_CAPTURED, sprintf(
            'Product #%d has no captured original-image snapshot; a destructive push cannot run.',
            $productId,
        ));
    }

    public static function notRestorable(string $mediaId): self
    {
        return new self(self::CODE_NOT_RESTORABLE, sprintf(
            'The original image %s was never backed up, so it cannot be replaced (it could not be restored).',
            $mediaId,
        ));
    }
}
