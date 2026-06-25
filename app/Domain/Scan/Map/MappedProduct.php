<?php

namespace App\Domain\Scan\Map;

/**
 * MappedProduct — the structured bag pdp-scanner returns to laravel-backend.
 *
 * Mirrors the §8 contract shape: product fields with per-field {value, confidence,
 * source}, the grouped variant axes + persistable variant rows, dimensions,
 * detected selectors, an overall aggregate confidence, the raw provenance, how the
 * page was fetched, and the merchant-facing warnings. laravel-backend validates
 * this, enforces the threshold, and persists Product(draft) + variants + selectors.
 * pdp-scanner NEVER sets scan_status or persists; it produces this bag.
 */
final readonly class MappedProduct
{
    /**
     * @param  array<string,array<string,mixed>>  $fields  field => {value, confidence, source}
     * @param  array<int,array<string,mixed>>  $variantAxes
     * @param  array<int,array<string,mixed>>  $variantRows  persistable ProductVariant rows
     * @param  array<string,mixed>  $dimensions
     * @param  array<string,array<string,mixed>>  $detectedSelectors  role => DetectedSelector::toArray()
     * @param  array<string,mixed>  $raw
     * @param  array<int,string>  $warnings
     */
    public function __construct(
        public array $fields,
        public array $variantAxes,
        public array $variantRows,
        public array $dimensions,
        public array $detectedSelectors,
        public float $confidence,
        public array $raw,
        public string $fetchedVia,
        public array $warnings,
    ) {}

    /** The scalar value of a mapped field (or null). */
    public function value(string $field): mixed
    {
        return $this->fields[$field]['value'] ?? null;
    }

    /** The confidence of a mapped field (or 0). */
    public function fieldConfidence(string $field): float
    {
        return (float) ($this->fields[$field]['confidence'] ?? 0.0);
    }

    /**
     * The Product attributes (DB column => value) derived from the mapped fields.
     * Prices are already minor-units integers; selectors/confidence are JSON.
     *
     * @return array<string,mixed>
     */
    public function toProductAttributes(): array
    {
        $price = $this->fields['price'] ?? [];

        return [
            'name' => $this->value('name'),
            'description' => $this->value('description'),
            'product_type' => $this->value('product_type'),
            'price_minor' => $price['value'] ?? null,
            'currency' => $price['currency'] ?? null,
            'sale_price_minor' => $price['sale_price'] ?? null,
            'regular_price_minor' => $price['regular_price'] ?? null,
            'price_is_range' => (bool) ($price['is_range'] ?? false),
            'main_image_url' => $this->value('main_image_url'),
            'images' => $this->value('images') ?? [],
            'physical_dimensions' => $this->dimensions,
            'field_confidence' => $this->fields,
            'detected_selectors' => $this->detectedSelectors,
            'scan_raw' => $this->raw,
            'fetched_via' => $this->fetchedVia,
            'warnings' => $this->warnings,
            'confidence' => round($this->confidence, 3),
        ];
    }
}
