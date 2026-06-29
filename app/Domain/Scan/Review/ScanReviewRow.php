<?php

namespace App\Domain\Scan\Review;

/**
 * ScanReviewRow — one immutable, UI-ready row of the A4 scan-review form.
 *
 * Covers BOTH groups with one shape:
 *  - a product field (title/price/description/variants/dimensions): value + level;
 *  - a page selector (add_to_cart/product_image/title/price/variations): the
 *    detected selector string + match count + level.
 *
 * The form binds straight to this — no recomputation in the UI. `level` is the
 * already-bucketed ConfidenceLevel (the badge map keys off it); `blocksConfirm`
 * is the single per-row gate signal (low + not_detected block until reviewed).
 * `editable` lets the UI render read-only vs editable rows uniformly.
 */
final readonly class ScanReviewRow
{
    // === ROW KINDS ===
    public const KIND_FIELD = 'field';

    public const KIND_SELECTOR = 'selector';

    /**
     * @param  string  $kind  KIND_FIELD | KIND_SELECTOR
     * @param  string  $key  the field name (e.g. "price") or selector role (e.g. "add_to_cart")
     * @param  string  $i18nLabelKey  the form-label i18n key (scan.field.* / scan.selector.*)
     * @param  bool  $optional  a best-effort row (variants / dimensions): its absence is shown but never blocks confirm
     * @param  array<string,mixed>|null  $detail  per-kind extra (selector match count, range flag, …)
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $i18nLabelKey,
        public mixed $value,
        public ConfidenceLevel $level,
        public bool $editable,
        public bool $optional = false,
        public ?int $matchedElementCount = null,
        public ?array $detail = null,
    ) {}

    /**
     * True when this row blocks confirm until the merchant reviews/edits it. An
     * optional row (best-effort variants / dimensions) never blocks: its level is
     * shown for context but a try-on does not require it.
     */
    public function blocksConfirm(): bool
    {
        return ! $this->optional && $this->level->blocksConfirm();
    }

    /** The UI-bound, immutable shape the A4 form renders (one field or one selector). */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'label_key' => $this->i18nLabelKey,
            'value' => $this->value,
            'confidence_level' => $this->level->level,
            'confidence_i18n_key' => $this->level->i18nKey(),
            'blocks_confirm' => $this->blocksConfirm(),
            'optional' => $this->optional,
            'editable' => $this->editable,
            'matched_element_count' => $this->matchedElementCount,
            'detail' => $this->detail,
        ];
    }
}
