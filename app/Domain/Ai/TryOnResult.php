<?php

namespace App\Domain\Ai;

/**
 * TryOnResult — the typed result of a try_on_generation.
 *
 * Carries the result image BYTES (not a URL — laravel-backend's media service
 * stores them before charging), the image mime, the parsed cost, the model
 * actually used (may be the fallback), and the OpenRouter generation id. This
 * layer stores no media and writes no ledger row.
 */
final readonly class TryOnResult
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
