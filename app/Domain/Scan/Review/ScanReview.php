<?php

namespace App\Domain\Scan\Review;

use App\Domain\Scan\ScanConstants;
use App\Models\Product;

/**
 * ScanReview — the immutable READ MODEL the A4 scan-review form binds to.
 *
 * Given a draft Product (its persisted scan result on field_confidence +
 * detected_selectors), it produces, for EVERY product field and EVERY page
 * selector, a ScanReviewRow with a bucketed ConfidenceLevel — so the form renders
 * straight from this with zero scan logic in the UI.
 *
 * It also exposes the ConfirmGate (the no-auto-approve predicate) computed over the
 * very same rows, so the "Confirm product" button and the server-side confirm
 * action read ONE source of truth — no UI/backend drift on what blocks confirm.
 *
 * Read-only: building a ScanReview never mutates the Product and never confirms.
 */
final readonly class ScanReview
{
    // === REVIEWABLE PRODUCT FIELDS (label key per field) ===
    // The A4 "product fields" group. Each maps a Product field_confidence key to
    // its form-label i18n key. Variants + dimensions are summarised below as their
    // own rows (collections, not scalar field_confidence entries).
    private const FIELD_LABEL_KEYS = [
        'name' => 'scan.field.title',
        'price' => 'scan.field.price',
        'description' => 'scan.field.description',
        'product_type' => 'scan.field.product_type',
        'main_image_url' => 'scan.field.main_image',
    ];

    private const VARIANTS_LABEL_KEY = 'scan.field.variants';

    private const DIMENSIONS_LABEL_KEY = 'scan.field.dimensions';

    private const SELECTOR_LABEL_PREFIX = 'scan.selector.';

    /**
     * @param  array<int,ScanReviewRow>  $fieldRows
     * @param  array<int,ScanReviewRow>  $selectorRows
     */
    public function __construct(
        public int|string|null $productId,
        public string $status,
        public ?float $overallConfidence,
        public array $fieldRows,
        public array $selectorRows,
        public ConfirmGate $gate,
    ) {}

    /** Build the read model from a persisted Product (its scan result). */
    public static function fromProduct(Product $product): self
    {
        $fieldConfidence = $product->field_confidence ?? [];
        $detected = $product->detected_selectors ?? [];

        $fieldRows = self::buildFieldRows($fieldConfidence, $product);
        $selectorRows = self::buildSelectorRows($detected);

        $gate = ConfirmGate::evaluate([...$fieldRows, ...$selectorRows]);

        return new self(
            productId: $product->getKey(),
            status: $product->status,
            overallConfidence: $product->confidence,
            fieldRows: $fieldRows,
            selectorRows: $selectorRows,
            gate: $gate,
        );
    }

    /**
     * @return array<int,ScanReviewRow>
     */
    private static function buildFieldRows(array $fieldConfidence, Product $product): array
    {
        $rows = [];

        foreach (self::FIELD_LABEL_KEYS as $field => $labelKey) {
            $meta = $fieldConfidence[$field] ?? null;
            $rows[] = self::scalarFieldRow($field, $labelKey, $meta);
        }

        $rows[] = self::variantsRow($product, $fieldConfidence);
        $rows[] = self::dimensionsRow($product, $fieldConfidence);

        return $rows;
    }

    /**
     * A scalar field row. A missing/empty value is not_detected (manual entry),
     * never a misleading "low confidence" on an absent field.
     *
     * @param  array<string,mixed>|null  $meta
     */
    private static function scalarFieldRow(string $field, string $labelKey, ?array $meta): ScanReviewRow
    {
        $value = $meta['value'] ?? null;
        $detected = self::isPresent($value);

        $level = ConfidenceLevel::fromScore(
            self::asFloatOrNull($meta['confidence'] ?? null),
            detected: $detected,
        );

        return new ScanReviewRow(
            kind: ScanReviewRow::KIND_FIELD,
            key: $field,
            i18nLabelKey: $labelKey,
            value: $value,
            level: $level,
            editable: true,
            detail: ['source' => $meta['source'] ?? null],
        );
    }

    /**
     * The variants summary row. Confidence is the LOWEST per-variant confidence
     * (the weakest variant governs the row, mirroring the per-axis miss warnings).
     */
    private static function variantsRow(Product $product, array $fieldConfidence): ScanReviewRow
    {
        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();

        $count = $variants->count();
        $detected = $count > 0;

        $minConfidence = $detected
            ? (float) $variants->min('confidence')
            : null;

        return new ScanReviewRow(
            kind: ScanReviewRow::KIND_FIELD,
            key: 'variants',
            i18nLabelKey: self::VARIANTS_LABEL_KEY,
            value: $variants->map(fn ($v) => [
                'id' => $v->getKey(),
                'options' => $v->options,
                'sku' => $v->sku,
            ])->all(),
            level: ConfidenceLevel::fromScore($minConfidence, detected: $detected),
            editable: true,
            optional: true, // a variant-less (one-size) product is legitimate; absence never blocks
            detail: ['count' => $count],
        );
    }

    /** The physical-dimensions summary row (best-effort; absent = not_detected). */
    private static function dimensionsRow(Product $product, array $fieldConfidence): ScanReviewRow
    {
        $dimensions = $product->physical_dimensions ?? [];
        $detected = is_array($dimensions) && $dimensions !== [];

        // Dimensions carry no per-field confidence on Product; trust is "detected or
        // not" — present is treated as medium (please confirm), absent as not_detected.
        $level = $detected
            ? ConfidenceLevel::fromScore(ScanConstants::LEVEL_MEDIUM_FLOOR, detected: true)
            : ConfidenceLevel::notDetected();

        return new ScanReviewRow(
            kind: ScanReviewRow::KIND_FIELD,
            key: 'physical_dimensions',
            i18nLabelKey: self::DIMENSIONS_LABEL_KEY,
            value: $detected ? $dimensions : null,
            level: $level,
            editable: true,
            optional: true, // best-effort fit hints; absence never blocks confirm
        );
    }

    /**
     * @return array<int,ScanReviewRow>
     */
    private static function buildSelectorRows(array $detected): array
    {
        $rows = [];

        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $meta = $detected[$role] ?? null;
            $rows[] = self::selectorRow($role, $meta);
        }

        return $rows;
    }

    /**
     * A selector row. A null primary or a 0/>1 match count is never high: a
     * selector that does not resolve to exactly one element is low-confidence by
     * construction (mirrors SelectorVerifier). The bucketed level still derives
     * from the persisted per-selector confidence so the merchant sees the nuance.
     *
     * @param  array<string,mixed>|null  $meta
     */
    private static function selectorRow(string $role, ?array $meta): ScanReviewRow
    {
        $primary = $meta['primary'] ?? null;
        $matchedCount = isset($meta['matched_count']) ? (int) $meta['matched_count'] : null;
        $detected = is_string($primary) && $primary !== '';

        $confidence = self::asFloatOrNull($meta['confidence'] ?? null);

        // A detected selector that does not resolve to exactly one element can never
        // be "high" — clamp its level down so the gate flags it for review.
        if ($detected && $matchedCount !== null && $matchedCount !== 1) {
            $confidence = min($confidence ?? 0.0, ScanConstants::LEVEL_MEDIUM_FLOOR - 0.01);
        }

        $level = ConfidenceLevel::fromScore($confidence, detected: $detected);

        return new ScanReviewRow(
            kind: ScanReviewRow::KIND_SELECTOR,
            key: $role,
            i18nLabelKey: self::SELECTOR_LABEL_PREFIX.$role,
            value: $primary,
            level: $level,
            editable: true,
            matchedElementCount: $matchedCount,
            detail: [
                'fallback_chain' => $meta['fallback_chain'] ?? [],
                'strategy' => $meta['strategy'] ?? null,
                'needs_review' => (bool) ($meta['needs_review'] ?? true),
            ],
        );
    }

    /** All rows (fields then selectors) — the full A4 binding payload. */
    public function rows(): array
    {
        return [...$this->fieldRows, ...$this->selectorRows];
    }

    /** The fully serialised UI payload the form binds to. */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'status' => $this->status,
            'overall_confidence' => $this->overallConfidence,
            'fields' => array_map(fn (ScanReviewRow $r) => $r->toArray(), $this->fieldRows),
            'selectors' => array_map(fn (ScanReviewRow $r) => $r->toArray(), $this->selectorRows),
            'gate' => $this->gate->toArray(),
        ];
    }

    private static function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private static function asFloatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
