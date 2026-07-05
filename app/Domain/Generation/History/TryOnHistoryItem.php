<?php

namespace App\Domain\Generation\History;

/**
 * TryOnHistoryItem — one row of the per-shop try-on history (WS2). Immutable
 * read-model: exactly what the merchant "Try-on history" list renders per
 * generation, with no live model leaking into Blade.
 *
 * Unlike the gallery (succeeded-only), history carries EVERY generation status
 * (the mechanism's activations, success and non-success alike). The shopper name +
 * id let the row deep-link to that lead's card (ViewEndUser) when there is a lead.
 *
 * The thumbnail is a SHORT-lived signed URL (or null) — never a raw disk path,
 * never a public URL. When retention has purged the result bytes (or a failed
 * generation never produced one) the URL is null and `purged` is true, so the UI
 * shows a placeholder instead of a broken image (mirrors the GalleryItem contract).
 */
final readonly class TryOnHistoryItem
{
    // === CONSTANTS ===
    // Outcome tokens mirror the generation status the badge resolves through (§5 map).
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public int $generationId,
        public string $status,
        // The shopper (lead) behind this try-on, or null for an anonymous attempt.
        public ?int $endUserId,
        // The lead's display name (full name → email → null), for the row label.
        public ?string $endUserName,
        public ?string $productName,
        // The selected variant options as a {axis => value} map (from the snapshot/variant).
        public array $variantOptions,
        // A short-lived signed URL to the result thumbnail, or null (none yet / purged).
        public ?string $resultThumbnailUrl,
        // True when the result bytes are gone (retention / never produced): show placeholder.
        public bool $purged,
        public ?string $failureCode,
        public ?string $createdAt,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /** True when there is a lead to deep-link to (an anonymous attempt has none). */
    public function hasLead(): bool
    {
        return $this->endUserId !== null;
    }
}
