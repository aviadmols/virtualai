<?php

namespace App\Domain\Ai;

/**
 * ScanResult — the typed result of a product_scan extraction.
 *
 * Carries the strict, schema-valid JSON (never a coerced blob), the parsed cost,
 * the model actually used (may be the fallback), and the OpenRouter generation
 * id. This layer does NOT persist anything; pdp-scanner validates + scores and
 * laravel-backend persists the Product.
 */
final readonly class ScanResult
{
    /** @param  array<string,mixed>  $json  schema-valid extraction */
    public function __construct(
        public array $json,
        public ParsedCost $cost,
        public string $modelUsed,
        public ?string $openrouterGenerationId,
        public bool $repaired = false,
    ) {}
}
