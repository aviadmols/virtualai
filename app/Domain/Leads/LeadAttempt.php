<?php

namespace App\Domain\Leads;

/**
 * LeadAttempt — one row of a lead's try-on attempt history (component-inventory A7).
 * Immutable read-model: exactly what the lead card renders per attempt, with no live
 * model leaking into Blade.
 *
 * The thumbnail is a SHORT-lived signed URL (or null) — never a raw disk path, never a
 * public URL. When retention has purged the result bytes the URL is null and `purged`
 * is true, so the UI shows `leads.history.purged` instead of a broken thumb (A7).
 */
final readonly class LeadAttempt
{
    // === CONSTANTS ===
    // Outcome tokens mirror the generation status the badge resolves through (§5 map).
    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_PROCESSING = 'processing';
    public const OUTCOME_CANCELLED = 'cancelled';

    public function __construct(
        public int $generationId,
        public string $status,
        public ?string $productName,
        // The selected variant options as a {axis => value} map (from the snapshot/variant).
        public array $variantOptions,
        // A short-lived signed URL to the result thumbnail, or null (none yet / purged).
        public ?string $resultThumbnailUrl,
        // True when the result bytes are gone (retention purge): show the purged copy.
        public bool $purged,
        public ?string $failureCode,
        public ?string $createdAt,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::OUTCOME_SUCCEEDED;
    }

    public function failed(): bool
    {
        return $this->status === self::OUTCOME_FAILED;
    }
}
