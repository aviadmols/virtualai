<?php

namespace App\Domain\Scan\Review;

use App\Domain\Scan\ScanConstants;

/**
 * ConfirmScanInput — the typed input the confirm/correct action accepts.
 *
 * Carries the merchant's corrected field values, the chosen page selectors
 * (manual or detected, per role), the corrected variant rows, and the set of
 * blocking-row keys the merchant reviewed/acknowledged (what unlocks the gate).
 *
 * A pure value object — no persistence, no tenancy. ConfirmScanAction consumes it,
 * re-runs the gate over the persisted scan with these reviewed keys, and only then
 * persists + confirms. Built from the form payload via fromArray() so the shape is
 * validated once, here, not scattered across the action.
 */
final readonly class ConfirmScanInput
{
    /**
     * @param  array<string,mixed>  $fieldValues  product field column => corrected value
     * @param  array<string,string>  $selectors  role => chosen CSS selector (manual or detected)
     * @param  array<int,array<string,mixed>>  $variants  corrected ProductVariant rows
     * @param  array<int,string>  $reviewedKeys  ConfirmGate identifiers ("field:price") the merchant reviewed
     */
    public function __construct(
        public array $fieldValues,
        public array $selectors,
        public array $variants,
        public array $reviewedKeys,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  the A4 form payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            fieldValues: self::onlyKnownFields((array) ($payload['fields'] ?? [])),
            selectors: self::onlyKnownSelectorRoles((array) ($payload['selectors'] ?? [])),
            variants: array_values((array) ($payload['variants'] ?? [])),
            reviewedKeys: array_values(array_filter(
                (array) ($payload['reviewed_keys'] ?? []),
                'is_string',
            )),
        );
    }

    /**
     * The Product column overrides to apply on confirm. Only writable product
     * columns are passed through (no mass-assignment of status/account_id/etc.).
     *
     * @return array<string,mixed>
     */
    public function productAttributes(): array
    {
        return $this->fieldValues;
    }

    /**
     * Keep only recognised product columns from the merchant's corrections so the
     * confirm path can never mass-assign a guarded column.
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    private static function onlyKnownFields(array $fields): array
    {
        return array_intersect_key($fields, array_flip(self::WRITABLE_PRODUCT_COLUMNS));
    }

    /**
     * Keep only the six known selector roles; drop anything else.
     *
     * @param  array<string,mixed>  $selectors
     * @return array<string,string>
     */
    private static function onlyKnownSelectorRoles(array $selectors): array
    {
        $out = [];

        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            if (isset($selectors[$role]) && is_string($selectors[$role])) {
                $out[$role] = $selectors[$role];
            }
        }

        return $out;
    }

    // === WRITABLE PRODUCT COLUMNS (merchant-correctable on confirm) ===
    // The columns the merchant may correct from A4. status/account_id/site_id and
    // the scan provenance are NEVER merchant-writable — the action sets status.
    public const WRITABLE_PRODUCT_COLUMNS = [
        'name',
        'description',
        'product_type',
        'price_minor',
        'currency',
        'sale_price_minor',
        'regular_price_minor',
        'price_is_range',
        'main_image_url',
        'images',
        'physical_dimensions',
    ];
}
