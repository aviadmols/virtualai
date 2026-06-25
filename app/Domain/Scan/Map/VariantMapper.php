<?php

namespace App\Domain\Scan\Map;

use App\Domain\Scan\Represent\PageRepresentation;

/**
 * VariantMapper — turn the model's flat variant rows into (a) grouped axes with a
 * detected control type and (b) the per-value rows persisted as ProductVariant.
 *
 * The model returns flat {axis, value, image, available} rows (the seed schema).
 * We group them by axis (color / size / material / model), detect each axis's
 * control type from the candidate hints (dropdown / radio / swatch / image-swatch),
 * and flag a single-axis result on a page that clearly has TWO axes (the classic
 * swatch+dropdown miss).
 */
final class VariantMapper
{
    // === CONSTANTS ===
    private const CONTROL_DROPDOWN = 'dropdown';
    private const CONTROL_RADIO = 'radio';
    private const CONTROL_SWATCH = 'swatch';
    private const CONTROL_IMAGE_SWATCH = 'image_swatch';
    private const CONTROL_UNKNOWN = 'unknown';

    public function __construct(
        private readonly ImageResolver $images,
    ) {}

    /**
     * Map flat variant rows into grouped axes + flat value rows.
     *
     * @param  array<int,array<string,mixed>>  $rows  model variant rows
     * @return array{axes: array<int,array<string,mixed>>, values: array<int,array<string,mixed>>, warnings: array<int,string>}
     */
    public function map(array $rows, PageRepresentation $representation): array
    {
        $byAxis = [];
        $values = [];
        $baseUrl = $representation->sourceUrl;

        foreach ($rows as $row) {
            $axis = isset($row['axis']) ? trim((string) $row['axis']) : '';
            $value = isset($row['value']) ? trim((string) $row['value']) : '';

            if ($axis === '' || $value === '') {
                continue;
            }

            $imageUrl = $this->images->resolveUrl($row['image'] ?? null, $baseUrl);
            $available = (bool) ($row['available'] ?? true);

            $byAxis[$axis][] = ['value' => $value, 'image' => $imageUrl, 'available' => $available];

            $values[] = [
                'options' => [$axis => $value],
                'image_url' => $imageUrl,
                'available' => $available,
                'sku' => $row['sku'] ?? null,
            ];
        }

        $axes = [];

        foreach ($byAxis as $axis => $entries) {
            $controlType = $this->detectControlType($axis, $entries, $representation);

            $axes[] = [
                'axis' => $axis,
                'values' => array_map(fn ($e) => $e['value'], $entries),
                'value_details' => $entries,
                'control_type' => $controlType,
                'confidence' => $this->axisConfidence($controlType, $entries),
            ];
        }

        return [
            'axes' => $axes,
            'values' => $values,
            'warnings' => $this->warnings($axes, $representation),
        ];
    }

    /**
     * Detect the control type for THIS axis. Per-axis, not page-wide: try to match
     * the axis name to a specific hint (a <select name="...Size"> -> dropdown, a
     * swatch labelled the axis value -> swatch), then fall back to image-swatch (the
     * axis's values carry images) and axis-name heuristics. A page with a color
     * swatch + a size dropdown must yield TWO different control types.
     *
     * @param  array<int,array<string,mixed>>  $entries
     */
    private function detectControlType(string $axis, array $entries, PageRepresentation $representation): string
    {
        $hasImages = array_filter($entries, fn ($e) => ($e['image'] ?? null) !== null) !== [];

        if ($hasImages) {
            return self::CONTROL_IMAGE_SWATCH;
        }

        // Try a hint that specifically names this axis.
        $matched = $this->controlForNamedAxis($axis, $representation);

        if ($matched !== null) {
            return $matched;
        }

        // Axis-name heuristic: color/material is a swatch, size is a dropdown.
        $lower = strtolower($axis);

        if (str_contains($lower, 'color') || str_contains($lower, 'colour') || str_contains($lower, 'material')) {
            return self::CONTROL_SWATCH;
        }

        if (str_contains($lower, 'size')) {
            return self::CONTROL_DROPDOWN;
        }

        return self::CONTROL_UNKNOWN;
    }

    /**
     * The control type of a hint whose name/option-name matches this axis. Returns
     * null when no hint names the axis (the caller falls back to heuristics).
     */
    private function controlForNamedAxis(string $axis, PageRepresentation $representation): ?string
    {
        $needle = strtolower($axis);
        $hints = $representation->candidateHints['variations'] ?? [];

        foreach ($hints as $hint) {
            $name = strtolower(
                ($hint['data']['data-option-name'] ?? '')
                .' '.($hint['aria']['aria-label'] ?? '')
                .' '.($hint['text'] ?? '')
            );

            // The <select> case: its option-name / surrounding text names the axis.
            $tag = $hint['tag'] ?? '';

            if ($tag === 'select' && (str_contains($name, $needle) || $name === '')) {
                // A select that names the axis (or the only select) is a dropdown.
                if (str_contains($name, $needle)) {
                    return self::CONTROL_DROPDOWN;
                }
            }

            // A swatch labelled with the axis value.
            $isSwatch = ($hint['data']['data-value'] ?? null) !== null || in_array('swatch', $hint['classes'] ?? [], true);

            if ($isSwatch && (str_contains($needle, 'color') || str_contains($needle, 'colour') || str_contains($needle, 'material'))) {
                return self::CONTROL_SWATCH;
            }

            if ($tag === 'input' && str_contains($name, $needle)) {
                return self::CONTROL_RADIO;
            }
        }

        return null;
    }

    /** Confidence for an axis: known control + multiple values is high. */
    private function axisConfidence(string $controlType, array $entries): float
    {
        $base = $controlType === self::CONTROL_UNKNOWN ? 0.6 : 0.85;

        return count($entries) > 1 ? $base : $base * 0.85;
    }

    /**
     * Warn when the page clearly has multiple variant axes but only one was
     * detected (the swatch + size-dropdown miss).
     *
     * @param  array<int,array<string,mixed>>  $axes
     * @return array<int,string>
     */
    private function warnings(array $axes, PageRepresentation $representation): array
    {
        $warnings = [];

        $variationHints = $representation->candidateHints['variations'] ?? [];
        $distinctControls = [];

        foreach ($variationHints as $hint) {
            $tag = $hint['tag'] ?? '';
            $isSwatch = in_array('swatch', $hint['classes'] ?? [], true) || ($hint['data']['data-value'] ?? null) !== null;

            if ($tag === 'select') {
                $distinctControls['dropdown'] = true;
            } elseif ($isSwatch) {
                $distinctControls['swatch'] = true;
            } elseif ($tag === 'input') {
                $distinctControls['radio'] = true;
            }
        }

        if (count($axes) <= 1 && count($distinctControls) >= 2) {
            $warnings[] = 'page shows multiple variant controls but only '.count($axes).' axis was detected; verify variants';
        }

        return $warnings;
    }
}
