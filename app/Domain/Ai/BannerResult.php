<?php

namespace App\Domain\Ai;

/**
 * BannerResult — the typed result of a banner_generation call (mirrors TryOnResult).
 *
 * Carries the generated banner image BYTES (not a URL — laravel-backend stores them before
 * charging), the mime, the parsed cost, the model actually used (may be the fallback), and
 * the provider generation id. This layer stores no media and writes no ledger row.
 */
final readonly class BannerResult
{
    public function __construct(
        public string $imageBytes,
        public string $mimeType,
        public ParsedCost $cost,
        public string $modelUsed,
        public ?string $openrouterGenerationId,
    ) {}

    /** Size of the returned image in bytes — for masked logging, never the bytes. */
    public function byteSize(): int
    {
        return strlen($this->imageBytes);
    }
}
