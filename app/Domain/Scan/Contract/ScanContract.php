<?php

namespace App\Domain\Scan\Contract;

use App\Domain\Scan\ScanConstants;
use App\Models\Product;

/**
 * ScanContract — the confirm/correct DATA SHAPE for the Phase-8 review UI.
 *
 * pdp-scanner OWNS this shape; admin-design-system renders it; widget-embed
 * consumes the confirmed result. It exposes, for a draft Product:
 *  - every editable field pre-filled with {value, confidence, source} so the UI
 *    can surface low-confidence fields for attention;
 *  - the six manual-selector-entry slots (raw CSS the merchant can override),
 *    each with the detected primary + fallback chain + match count;
 *  - the element-pick payload shape the picker POSTs back;
 *  - the re-scan signal.
 *
 * A Product reaches the widget ONLY at status=confirmed; this contract never
 * confirms on its own — the merchant's confirm() is the gate.
 */
final class ScanContract
{
    /**
     * The editable-field descriptors the UI renders. Each carries the current
     * value, confidence, source, and whether it should be flagged for review.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function editableFields(Product $product): array
    {
        $fields = $product->field_confidence ?? [];
        $out = [];

        foreach (self::FIELD_DEFINITIONS as $key => $definition) {
            $meta = $fields[$key] ?? ['value' => null, 'confidence' => 0.0, 'source' => ScanConstants::SOURCE_MODEL_INFERRED];

            $out[$key] = [
                'label' => $definition['label'],
                'type' => $definition['type'],
                'value' => $meta['value'] ?? null,
                'confidence' => (float) ($meta['confidence'] ?? 0.0),
                'source' => $meta['source'] ?? ScanConstants::SOURCE_MODEL_INFERRED,
                'needs_review' => (float) ($meta['confidence'] ?? 0.0) < ScanConstants::REVIEW_FLOOR
                    || ($meta['source'] ?? null) === ScanConstants::SOURCE_MODEL_INFERRED,
                'editable' => true,
            ];
        }

        return $out;
    }

    /**
     * The manual-selector-entry slots: detected primary + fallback chain + match
     * count per role, plus an editable raw-CSS override field. The UI re-verifies
     * a typed selector live via SelectorReverifier.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function selectorSlots(Product $product): array
    {
        $detected = $product->detected_selectors ?? [];
        $out = [];

        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $meta = $detected[$role] ?? [
                'primary' => null,
                'fallback_chain' => [],
                'confidence' => 0.0,
                'matched_count' => 0,
                'needs_review' => true,
            ];

            $out[$role] = [
                'role' => $role,
                'primary' => $meta['primary'] ?? null,
                'fallback_chain' => $meta['fallback_chain'] ?? [],
                'confidence' => (float) ($meta['confidence'] ?? 0.0),
                'matched_count' => (int) ($meta['matched_count'] ?? 0),
                'needs_review' => (bool) ($meta['needs_review'] ?? true),
                'manual_override' => true, // merchant may type a raw CSS selector
            ];
        }

        return $out;
    }

    /**
     * The element-pick payload shape the picker POSTs back. pdp-scanner turns this
     * into a verified primary + fallback chain (admin-design-system builds the UI;
     * the shape + the verification are ours).
     *
     * @return array<string,string>
     */
    public static function elementPickShape(): array
    {
        return [
            'role' => 'one of ScanConstants::SELECTOR_ROLES',
            'css_path' => 'the full CSS path of the clicked element',
            'suggested_selectors' => 'array, stable -> positional',
            'tag' => 'the element tag name',
            'attributes' => 'object {id, data-*, aria-*, class}',
            'text_sample' => 'a short text sample of the element',
            'bounding_box' => 'object {x, y, width, height}',
        ];
    }

    /** The full contract bag the review UI renders for a draft product. */
    public static function forProduct(Product $product): array
    {
        return [
            'product_id' => $product->getKey(),
            'status' => $product->status,
            'overall_confidence' => $product->confidence,
            'warnings' => $product->warnings ?? [],
            'fetched_via' => $product->fetched_via,
            'fields' => self::editableFields($product),
            'selectors' => self::selectorSlots($product),
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->getKey(),
                'options' => $v->options,
                'image_url' => $v->image_url,
                'sku' => $v->sku,
                'available' => $v->available,
                'editable' => true,
            ])->all(),
            'element_pick_shape' => self::elementPickShape(),
            'actions' => [
                'confirm' => 'POST confirm — transitions draft -> confirmed (the only path live)',
                'rescan' => 'POST rescan — re-runs represent()+extract(); presents a diff, never clobbers a confirmed product',
            ],
        ];
    }

    // === FIELD DEFINITIONS (label + input type for the UI) ===
    private const FIELD_DEFINITIONS = [
        'name' => ['label' => 'Product name', 'type' => 'text'],
        'description' => ['label' => 'Description', 'type' => 'textarea'],
        'product_type' => ['label' => 'Product type', 'type' => 'text'],
        'price' => ['label' => 'Price', 'type' => 'money'],
        'main_image_url' => ['label' => 'Main image', 'type' => 'image_url'],
        'images' => ['label' => 'Gallery images', 'type' => 'image_url_list'],
    ];
}
