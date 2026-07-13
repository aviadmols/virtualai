<?php

namespace App\Domain\Ai;

/**
 * ProductImageResult — the bytes + the HONEST cost of ONE finished product-image transform.
 *
 * The cost is a ParsedCost, never a float: a provider that gives no usable cost yields an
 * `unavailable` cost, and the money path then refuses to charge rather than invent a number.
 */
final readonly class ProductImageResult
{
    public function __construct(
        public string $imageBytes,
        public string $mimeType,
        public ParsedCost $cost,
        public string $modelUsed,
        public string $provider,
        public ?string $providerGenerationId = null,
    ) {}
}
