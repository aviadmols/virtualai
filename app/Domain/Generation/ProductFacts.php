<?php

namespace App\Domain\Generation;

use App\Domain\Shopify\Products\ShopifyProductMapper;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * ProductFacts — the REAL product data the try-on prompt may reference.
 *
 * Until now only name / type / variant / height reached the model: description,
 * materials, the full option map and the measured dimensions were persisted and then
 * ignored. Shopify-sourced products carry all of it verbatim from the merchant's own
 * store record, so the prompt can finally say what the garment is made of and how it is
 * cut — which is exactly what makes a try-on look right.
 *
 * It exposes each fact as its OWN placeholder (an admin-authored prompt may use just
 * {{materials}}), plus a pre-composed {{product_details}} clause that OMITS whatever is
 * unknown — so a product with no materials never renders "It is made of ." into the
 * prompt. Substitution is strtr (OperationConfig::substituteUser), never Blade.
 */
final readonly class ProductFacts
{
    // === CONSTANTS ===
    // The placeholders this class supplies. Mirrors the seeded try_on prompts.
    public const VAR_DESCRIPTION = 'description';

    public const VAR_MATERIALS = 'materials';

    public const VAR_OPTIONS = 'options';

    public const VAR_DIMENSIONS = 'dimensions';

    public const VAR_PRODUCT_DETAILS = 'product_details';

    // A description is context, not an essay: long marketing copy dilutes the image
    // prompt and burns tokens.
    private const DESCRIPTION_MAX = 500;

    // physical_dimensions keys that are NOT measurements.
    private const DIMENSION_NON_MEASUREMENT_KEYS = [
        ShopifyProductMapper::DIMENSION_MATERIALS,
        'picks', // the merchant's visual size/weight picks (ScanConstants::DIMENSION_PICKS_KEY)
    ];

    // How many measurements reach the prompt (the rest is noise for an image model).
    private const DIMENSIONS_MAX = 6;

    // Composed-clause templates. strtr placeholders, never Blade.
    private const CLAUSE_OPTIONS = 'Selected options: {{v}}.';

    private const CLAUSE_MATERIALS = 'The item is made of {{v}}.';

    private const CLAUSE_DIMENSIONS = 'Measurements: {{v}}.';

    private const CLAUSE_DESCRIPTION = 'Product description: {{v}}';

    private function __construct(
        public string $description,
        public string $materials,
        public string $options,
        public string $dimensions,
    ) {}

    /** Read the facts off a product + the selected variant (both already tenant-scoped). */
    public static function for(Product $product, ?ProductVariant $variant): self
    {
        $physical = is_array($product->physical_dimensions) ? $product->physical_dimensions : [];

        return new self(
            description: self::description($product),
            materials: self::materials($physical),
            options: self::options($variant),
            dimensions: self::dimensions($physical),
        );
    }

    /**
     * The prompt placeholders. {{product_details}} is the composed clause the seeded
     * prompts use; the individual vars are there for an admin-authored prompt.
     *
     * @return array<string,string>
     */
    public function toVars(): array
    {
        return [
            self::VAR_DESCRIPTION => $this->description,
            self::VAR_MATERIALS => $this->materials,
            self::VAR_OPTIONS => $this->options,
            self::VAR_DIMENSIONS => $this->dimensions,
            self::VAR_PRODUCT_DETAILS => $this->productDetails(),
        ];
    }

    /**
     * The composed clause: only the facts we actually have. Unknown facts contribute
     * NOTHING (not an empty sentence), so a sparse product yields a clean prompt.
     */
    public function productDetails(): string
    {
        $parts = [];

        foreach ([
            [self::CLAUSE_OPTIONS, $this->options],
            [self::CLAUSE_MATERIALS, $this->materials],
            [self::CLAUSE_DIMENSIONS, $this->dimensions],
            [self::CLAUSE_DESCRIPTION, $this->description],
        ] as [$template, $value]) {
            if ($value !== '') {
                $parts[] = strtr($template, ['{{v}}' => $value]);
            }
        }

        return implode(' ', $parts);
    }

    /** Plain-text, bounded product description (HTML never reaches the model). */
    private static function description(Product $product): string
    {
        $raw = (string) ($product->description ?? '');

        if ($raw === '') {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));

        return $text === '' ? '' : Str::limit($text, self::DESCRIPTION_MAX);
    }

    /** Materials as a human list ("cotton, linen"), from physical_dimensions.materials. */
    private static function materials(array $physical): string
    {
        $materials = $physical[ShopifyProductMapper::DIMENSION_MATERIALS] ?? null;

        if (is_string($materials)) {
            return trim($materials);
        }

        if (! is_array($materials)) {
            return '';
        }

        $values = array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $materials),
            static fn (string $v): bool => $v !== '',
        );

        return implode(', ', array_values($values));
    }

    /** The SELECTED variant's full option map ("Color: Midnight Blue, Size: M"). */
    private static function options(?ProductVariant $variant): string
    {
        $options = $variant?->options;

        if (! is_array($options) || $options === []) {
            return '';
        }

        $pairs = [];

        foreach ($options as $axis => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $pairs[] = trim((string) $axis).': '.trim((string) $value);
        }

        return implode(', ', $pairs);
    }

    /**
     * The measured fit hints ("chest: 100 cm, length: 70 cm"). SCALARS ONLY — a nested
     * dimension group (a size map / the merchant's picks) is skipped rather than
     * stringified into noise (TS-PDPSCAN-007: a nested group blows up naive rendering).
     */
    private static function dimensions(array $physical): string
    {
        $pairs = [];

        foreach ($physical as $key => $value) {
            if (in_array((string) $key, self::DIMENSION_NON_MEASUREMENT_KEYS, true)) {
                continue;
            }

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $pairs[] = trim((string) $key).': '.trim((string) $value);

            if (count($pairs) >= self::DIMENSIONS_MAX) {
                break;
            }
        }

        return implode(', ', $pairs);
    }
}
