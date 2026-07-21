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

    // The prefix for a per-product metafield token — {{mf_custom_material}} etc. The merchant's
    // own custom fields, synced into scan_raw.shopify.metafields, become weavable placeholders.
    public const VAR_METAFIELD_PREFIX = 'mf_';

    // A metafield value is a hint, not an essay — bound it like the description.
    private const METAFIELD_VALUE_MAX = 200;

    // Where the mapper persists the product's text metafields (ShopifyProductMapper::metafields).
    private const RAW_ROOT = 'shopify';

    private const RAW_METAFIELDS = 'metafields';

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
        /** @var array<string,string> token => value, for the product's synced metafields */
        public array $metafields = [],
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
            metafields: self::metafieldVars($product),
        );
    }

    /**
     * The deterministic token name for a metafield — the SAME derivation the prompt editor shows
     * and the generation reads, so {{mf_custom_material}} always resolves. Non-alphanumerics fold
     * to underscores (a strtr placeholder must be a plain identifier).
     */
    public static function metafieldToken(string $namespace, string $key): string
    {
        $slug = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($s)));

        return self::VAR_METAFIELD_PREFIX.trim($slug($namespace).'_'.$slug($key), '_');
    }

    /**
     * The metafield tokens a product actually offers, for the prompt editor: each is
     * {token, label ("namespace.key"), value (cleaned preview)}. Empty when the product has none.
     *
     * @return array<int,array{token:string,label:string,value:string}>
     */
    public static function availableMetafields(Product $product): array
    {
        $out = [];

        foreach (self::rawMetafields($product) as $mf) {
            $value = self::metafieldValue((string) ($mf['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            $out[] = [
                'token' => self::metafieldToken((string) ($mf['namespace'] ?? ''), (string) ($mf['key'] ?? '')),
                'label' => trim((string) ($mf['namespace'] ?? '').'.'.(string) ($mf['key'] ?? ''), '.'),
                'value' => $value,
            ];
        }

        return $out;
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
        ] + $this->metafields;
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

    /**
     * The product's metafields as prompt vars (token => cleaned value), read from the synced
     * scan_raw.shopify.metafields. A blank value contributes no token.
     *
     * @return array<string,string>
     */
    private static function metafieldVars(Product $product): array
    {
        $vars = [];

        foreach (self::rawMetafields($product) as $mf) {
            $value = self::metafieldValue((string) ($mf['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            $vars[self::metafieldToken((string) ($mf['namespace'] ?? ''), (string) ($mf['key'] ?? ''))] = $value;
        }

        return $vars;
    }

    /** The raw text-metafield list persisted by the mapper. @return array<int,array<string,mixed>> */
    private static function rawMetafields(Product $product): array
    {
        $raw = is_array($product->scan_raw) ? $product->scan_raw : [];
        $list = $raw[self::RAW_ROOT][self::RAW_METAFIELDS] ?? [];

        return is_array($list) ? $list : [];
    }

    /**
     * Clean a metafield value for a prompt: a `list.*` JSON array becomes "a, b, c"; HTML is
     * stripped, whitespace collapsed, and the result bounded. Anything unusable becomes ''.
     */
    private static function metafieldValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // A list metafield stores a JSON array of scalars.
        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $parts = array_filter(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                    $decoded,
                ), static fn (string $v): bool => $v !== '');

                $value = implode(', ', $parts);
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return $text === '' ? '' : Str::limit($text, self::METAFIELD_VALUE_MAX);
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
