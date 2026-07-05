<?php

namespace App\Domain\Scan\Review;

/**
 * DimensionPickResult — the typed outcome of verifying a merchant-picked
 * size/weight source element against a page DOM.
 *
 * Carries the same 1/0/N verdict a selector test does (so the review row can show
 * "resolves to one" feedback) PLUS the text VALUE read from the matched element —
 * because a dimension pick is not just a selector, it captures the value the
 * try-on prompt will use. A pure value object; the reading happens in
 * DimensionPicker, the persistence in ConfirmScanAction.
 */
final readonly class DimensionPickResult
{
    public function __construct(
        public string $role,
        public string $selector,
        public int $matchedCount,
        public ?string $value,
    ) {}

    /** True only when the selector resolves to exactly one element (a clean pick). */
    public function resolvesToOne(): bool
    {
        return $this->matchedCount === 1;
    }

    /** The UI-bound verdict shape (mirrors the widget-appearance pickVerdict). */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'selector' => $this->selector,
            'count' => $this->matchedCount,
            'ok' => $this->resolvesToOne(),
            'value' => $this->value,
        ];
    }
}
