<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Scan\Map\MappedProduct;
use App\Domain\Scan\ScanConstants;

/**
 * ShopifyProductMapper — one Admin GraphQL product node -> the SAME MappedProduct bag
 * the PDP scanner produces, so PersistProduct (the single writer) stays rail-agnostic.
 *
 * The defining difference from a scan: NOTHING here is a guess. Every field comes from
 * the merchant's own store record, so every field carries {confidence: 1.0, source:
 * 'shopify'} — which means the ConfirmGate (which blocks on low / not_detected rows)
 * never blocks an imported product. The merchant still confirms EXPLICITLY (the
 * no-auto-approve law is untouched); confirming is simply never obstructed.
 *
 * Selectors are the platform's OS 2.0 defaults from config — a Shopify storefront has a
 * known DOM contract, and the Theme App Extension supplies the authoritative product/
 * variant context anyway, so no selector detection runs. They are marked
 * strategy=platform_default with NO match count (we never verified them against a live
 * page — claiming a count we did not measure would be a lie the review UI would echo).
 *
 * Money: Shopify returns decimal strings ("49.90"); we persist integer MINOR units
 * (x100 — the project-wide ParsedMoney convention), never a float.
 */
final class ShopifyProductMapper
{
    // === CONSTANTS ===
    // Everything a store tells us about its own product is authoritative.
    private const CONFIDENCE = 1.0;

    private const SOURCE = ScanConstants::SOURCE_SHOPIFY;

    private const FETCHED_VIA = 'shopify_admin_api';

    // The ONE product status that means "the store sells this" (the catalog walk's filter).
    private const STATUS_ACTIVE = 'ACTIVE';

    // Shopify's placeholder option for a product with no real options.
    private const DEFAULT_OPTION_NAME = 'Title';

    private const DEFAULT_OPTION_VALUE = 'Default Title';

    // Option axes that literally name a material (a strong, deterministic signal the
    // try-on prompt can use). Compared case-insensitively.
    private const MATERIAL_AXES = ['material', 'materials', 'fabric', 'composition', 'metal'];

    // The materials key inside physical_dimensions (ProductFacts reads it back).
    public const DIMENSION_MATERIALS = 'materials';

    // Metafield types whose stored `value` is human TEXT we can weave into a prompt. Reference
    // types (metaobject/file/product) store a gid; rich_text/json store a blob — all excluded.
    private const METAFIELD_TEXT_TYPES = [
        'single_line_text_field',
        'multi_line_text_field',
        'list.single_line_text_field',
        'number_integer',
        'number_decimal',
        'boolean',
        'color',
        'rating',
        'dimension',
        'weight',
        'volume',
        'url',
        'date',
        'date_time',
    ];

    // Config key holding the platform-default OS 2.0 selectors (role => css).
    private const CFG_SELECTORS = 'shopify.selectors';

    private const SELECTOR_STRATEGY = 'platform_default';

    // Minor-unit factor (the project's ParsedMoney convention: minor = major x 100).
    private const MINOR_FACTOR = 100;

    /**
     * @param  array<string,mixed>  $node  a Product node from the Admin GraphQL API
     * @param  string  $shopDomain  used to synthesise a storefront url when the product is not published
     */
    public function map(array $node, string $shopDomain): MappedProduct
    {
        $variantNodes = $this->nodes($node['variants'] ?? []);
        $price = $this->price($node, $variantNodes);
        $images = $this->images($node);
        $materials = $this->materials($node, $variantNodes);

        return new MappedProduct(
            fields: [
                'name' => $this->field($node['title'] ?? null),
                'description' => $this->field($this->description($node)),
                'product_type' => $this->field($this->blankToNull($node['productType'] ?? null)),
                'price' => $price,
                'main_image_url' => $this->field($node['featuredImage']['url'] ?? ($images[0] ?? null)),
                'images' => $this->field($images),
            ],
            variantAxes: $this->axes($node),
            variantRows: $this->variantRows($variantNodes),
            dimensions: $materials === [] ? [] : [self::DIMENSION_MATERIALS => $materials],
            detectedSelectors: $this->selectors(),
            confidence: self::CONFIDENCE,
            raw: [
                'shopify' => [
                    'id' => $node['id'] ?? null,
                    'handle' => $node['handle'] ?? null,
                    'status' => $node['status'] ?? null,
                    'vendor' => $node['vendor'] ?? null,
                    'tags' => $node['tags'] ?? [],
                    'collections' => $this->collections($node),
                    'metafields' => $this->metafields($node),
                    'options' => $node['options'] ?? [],
                ],
            ],
            fetchedVia: self::FETCHED_VIA,
            warnings: [],
        );
    }

    /** The identity of this product on the Vsio side (GID + handle + storefront url). */
    public function origin(array $node, string $shopDomain): ShopifyProductRef
    {
        return new ShopifyProductRef(
            gid: (string) ($node['id'] ?? ''),
            handle: $this->blankToNull($node['handle'] ?? null),
            url: $this->storefrontUrl($node, $shopDomain),
            active: $this->isActive($node),
        );
    }

    /**
     * The collections this product belongs to, each as {handle, title} — the input to a
     * collection-scoped button-visibility rule (ButtonVisibility). Skips any malformed node.
     *
     * @return array<int,array{handle: string, title: string}>
     */
    private function collections(array $node): array
    {
        $nodes = $node['collections']['nodes'] ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $out = [];
        foreach ($nodes as $c) {
            $handle = is_array($c) ? (string) ($c['handle'] ?? '') : '';
            $title = is_array($c) ? (string) ($c['title'] ?? '') : '';

            if ($handle !== '' || $title !== '') {
                $out[] = ['handle' => $handle, 'title' => $title];
            }
        }

        return $out;
    }

    /**
     * The product's TEXT metafields (the merchant's own custom fields), each as
     * {namespace, key, type, value}. Only value-as-TEXT types are kept — reference metafields
     * (metaobject / file / product) store a gid and rich_text/json store a blob, neither of which
     * belongs in a try-on prompt. This is what the prompt editor offers as {{mf_*}} tokens.
     *
     * @return array<int,array{namespace:string,key:string,type:string,value:string}>
     */
    private function metafields(array $node): array
    {
        $nodes = $node['metafields']['nodes'] ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $out = [];
        foreach ($nodes as $m) {
            if (! is_array($m)) {
                continue;
            }

            $type = (string) ($m['type'] ?? '');
            $key = (string) ($m['key'] ?? '');
            $value = (string) ($m['value'] ?? '');

            if ($key === '' || $value === '' || ! in_array($type, self::METAFIELD_TEXT_TYPES, true)) {
                continue;
            }

            $out[] = [
                'namespace' => (string) ($m['namespace'] ?? ''),
                'key' => $key,
                'type' => $type,
                'value' => $value,
            ];
        }

        return $out;
    }

    /**
     * Does the STORE still offer this product? The catalog walk only asks for
     * `status:active`, so an unpublished product is archived locally — and then a
     * products/update webhook re-reads it and would RE-ACTIVATE it if the writer ignored
     * the status. The product would flap in and out of the widget on every save.
     *
     * FAIL-SAFE: an absent/unknown status is NEVER a reason to archive. Only an EXPLICIT
     * non-ACTIVE status (DRAFT / ARCHIVED) deactivates the local product.
     */
    private function isActive(array $node): bool
    {
        $status = $node['status'] ?? null;

        return ! is_string($status) || strtoupper($status) === self::STATUS_ACTIVE;
    }

    /**
     * The storefront url: the published onlineStoreUrl when Shopify gives one, else the
     * canonical /products/{handle} path on the shop domain (an unpublished product still
     * needs a stable, non-empty source_url — the column is NOT NULL).
     */
    private function storefrontUrl(array $node, string $shopDomain): string
    {
        $online = $this->blankToNull($node['onlineStoreUrl'] ?? null);

        if ($online !== null) {
            return $online;
        }

        $handle = $this->blankToNull($node['handle'] ?? null)
            ?? (string) (ShopifyGid::id($node['id'] ?? null) ?? '');

        return 'https://'.$shopDomain.'/products/'.$handle;
    }

    /** A field entry in the MappedProduct contract shape. */
    private function field(mixed $value): array
    {
        return [
            'value' => $value,
            'confidence' => self::CONFIDENCE,
            'source' => self::SOURCE,
        ];
    }

    /** Plain-text description (the HTML body is kept in the raw bag, not the prompt). */
    private function description(array $node): ?string
    {
        return $this->blankToNull($node['description'] ?? null)
            ?? $this->blankToNull(strip_tags((string) ($node['descriptionHtml'] ?? '')));
    }

    /**
     * The price field: min variant price in minor units + currency, is_range when the
     * store's own range spans two amounts. sale/regular come from the first variant's
     * price vs compareAtPrice (Shopify's own "on sale" shape).
     */
    private function price(array $node, array $variantNodes): array
    {
        $min = $node['priceRangeV2']['minVariantPrice'] ?? [];
        $max = $node['priceRangeV2']['maxVariantPrice'] ?? [];

        $minMinor = $this->minor($min['amount'] ?? null);
        $maxMinor = $this->minor($max['amount'] ?? null);

        $first = $variantNodes[0] ?? [];
        $compareAt = $this->minor($first['compareAtPrice'] ?? null);
        $current = $this->minor($first['price'] ?? null) ?? $minMinor;

        $onSale = $compareAt !== null && $current !== null && $compareAt > $current;

        return [
            'value' => $minMinor,
            'currency' => $this->blankToNull($min['currencyCode'] ?? null),
            'is_range' => $minMinor !== null && $maxMinor !== null && $minMinor !== $maxMinor,
            'sale_price' => $onSale ? $current : null,
            'regular_price' => $onSale ? $compareAt : ($current ?? $minMinor),
            'confidence' => self::CONFIDENCE,
            'source' => self::SOURCE,
        ];
    }

    /** @return array<int,string> */
    private function images(array $node): array
    {
        $urls = [];

        foreach ($this->nodes($node['images'] ?? []) as $image) {
            $url = $this->blankToNull($image['url'] ?? null);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The option axes exactly as the merchant defined them in Shopify (name + values +
     * order). Control type is unknown to the Admin API — the Theme App Extension drives
     * selection in a Shopify storefront, so no control is guessed here.
     *
     * @return array<int,array<string,mixed>>
     */
    private function axes(array $node): array
    {
        $axes = [];

        foreach ((array) ($node['options'] ?? []) as $option) {
            $name = $this->blankToNull($option['name'] ?? null);
            $values = array_values(array_filter((array) ($option['values'] ?? [])));

            if ($name === null || $this->isDefaultOption($name, $values[0] ?? null)) {
                continue;
            }

            $axes[] = [
                'axis' => $name,
                'values' => $values,
                'position' => (int) ($option['position'] ?? count($axes) + 1),
                'confidence' => self::CONFIDENCE,
            ];
        }

        return $axes;
    }

    /**
     * The persistable variant rows. `external_id` (the variant GID) is the upsert key
     * PersistProduct matches on, so a re-sync updates the SAME row and every past
     * generation's product_variant_id stays valid.
     *
     * @param  array<int,array<string,mixed>>  $variantNodes
     * @return array<int,array<string,mixed>>
     */
    private function variantRows(array $variantNodes): array
    {
        $rows = [];

        foreach ($variantNodes as $index => $variant) {
            $rows[] = [
                'external_id' => $this->blankToNull($variant['id'] ?? null),
                'options' => $this->options($variant),
                'position' => (int) ($variant['position'] ?? $index + 1),
                'price_minor' => $this->minor($variant['price'] ?? null),
                'image_url' => $this->blankToNull($variant['image']['url'] ?? null),
                'sku' => $this->blankToNull($variant['sku'] ?? null),
                'available' => (bool) ($variant['availableForSale'] ?? true),
                'confidence' => self::CONFIDENCE,
            ];
        }

        return $rows;
    }

    /**
     * {axis => value} for one variant. Shopify's placeholder axis (Title/Default Title,
     * used when a product has no real options) maps to an EMPTY map — a one-variant
     * product should not show the shopper a fake "Title: Default Title" choice.
     *
     * @return array<string,string>
     */
    private function options(array $variant): array
    {
        $options = [];

        foreach ((array) ($variant['selectedOptions'] ?? []) as $selected) {
            $name = $this->blankToNull($selected['name'] ?? null);
            $value = $this->blankToNull($selected['value'] ?? null);

            if ($name === null || $value === null || $this->isDefaultOption($name, $value)) {
                continue;
            }

            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Materials the try-on prompt can use: the values of an option axis that literally
     * names a material (Material / Fabric / Composition / Metal). Deterministic — no
     * keyword-sniffing of free text, so a wrong material never reaches the model.
     *
     * @return array<int,string>
     */
    private function materials(array $node, array $variantNodes): array
    {
        $materials = [];

        foreach ((array) ($node['options'] ?? []) as $option) {
            $name = strtolower((string) ($option['name'] ?? ''));

            if (! in_array($name, self::MATERIAL_AXES, true)) {
                continue;
            }

            foreach ((array) ($option['values'] ?? []) as $value) {
                $value = $this->blankToNull(is_string($value) ? $value : null);

                if ($value !== null) {
                    $materials[] = $value;
                }
            }
        }

        return array_values(array_unique($materials));
    }

    /**
     * The platform's OS 2.0 default selectors (config-driven, never a literal here).
     * Confidence 1.0 with NO matched_count: a default is authoritative for the Shopify
     * DOM contract, but we did not verify it against a live page — so the review UI
     * shows it as high-confidence without inventing a match count.
     *
     * @return array<string,array<string,mixed>>
     */
    private function selectors(): array
    {
        $selectors = [];

        foreach ((array) config(self::CFG_SELECTORS, []) as $role => $css) {
            $selectors[$role] = [
                'primary' => $css,
                'fallback_chain' => [],
                'confidence' => self::CONFIDENCE,
                'matched_count' => null,
                'strategy' => self::SELECTOR_STRATEGY,
                'needs_review' => false,
            ];
        }

        return $selectors;
    }

    /** Unwrap a GraphQL connection ({nodes: [...]}) into its node list. */
    private function nodes(mixed $connection): array
    {
        return array_values((array) ($connection['nodes'] ?? []));
    }

    private function isDefaultOption(string $name, ?string $value): bool
    {
        return $name === self::DEFAULT_OPTION_NAME && $value === self::DEFAULT_OPTION_VALUE;
    }

    /** Shopify's decimal money string -> integer minor units (never a float column). */
    private function minor(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round(((float) $amount) * self::MINOR_FACTOR);
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
