<?php

namespace App\Domain\Gallery;

/**
 * GalleryItem — one row of the merchant try-on gallery. Immutable read-model: exactly
 * what the merchant gallery grid renders per generation, with no live model in the view.
 *
 * The thumbnail is a SHORT-lived signed URL (or null) — never a raw disk path, never a
 * public URL. When retention has purged the result bytes the URL is null and `purged` is
 * true, so the UI shows a "purged" placeholder instead of a broken thumbnail (mirrors the
 * LeadAttempt contract).
 */
final readonly class GalleryItem
{
    // === CONSTANTS ===
    // Outcome tokens mirror the generation status the badge resolves through.
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public int $generationId,
        public string $status,
        public ?int $endUserId,
        public ?string $productName,
        // The selected variant options as a {axis => value} map (from the snapshot/variant).
        public array $variantOptions,
        // A short-lived signed URL to the result thumbnail, or null (none yet / purged).
        public ?string $resultThumbnailUrl,
        // True when the result bytes are gone (retention purge): show the purged placeholder.
        public bool $purged,
        public ?string $failureCode,
        public ?string $createdAt,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
