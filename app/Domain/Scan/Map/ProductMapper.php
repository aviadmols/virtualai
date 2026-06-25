<?php

namespace App\Domain\Scan\Map;

use App\Domain\Ai\ScanResult;
use App\Domain\Scan\Represent\PageRepresentation;
use App\Domain\Scan\Represent\StructuredData;
use App\Domain\Scan\ScanConstants;
use App\Domain\Scan\Selectors\SelectorDetector;

/**
 * ProductMapper — map the model's strict ScanResult JSON into the Product shape,
 * scoring a per-field {value, confidence, source} so the review queue + UI can
 * surface low-confidence guesses.
 *
 * Reconciliation: structured data (JSON-LD > OG > microdata) is the highest-trust
 * source and OVERRIDES a bare model_inferred value when present; the model fills
 * the gaps. Every field's source is tagged; model_inferred is lowest-trust and
 * always flagged. Prices are locale-parsed to minor units; images lazy/srcset
 * resolved; variants grouped into axes; selectors detected + count-verified.
 */
final class ProductMapper
{
    public function __construct(
        private readonly MoneyParser $money,
        private readonly ImageResolver $images,
        private readonly VariantMapper $variants,
        private readonly SelectorDetector $selectors,
    ) {}

    public function map(ScanResult $result, PageRepresentation $representation): MappedProduct
    {
        $json = $result->json;
        $structured = $representation->structuredData;
        $productNode = StructuredData::productNode($structured['jsonld']);
        $og = $structured['og'];
        $micro = $structured['microdata'];
        $baseUrl = $representation->sourceUrl;

        $warnings = [];

        $fields = [];

        // --- name ---
        $fields['name'] = $this->pick(
            jsonld: $productNode['name'] ?? null,
            og: $og['og:title'] ?? null,
            micro: $micro['name'] ?? null,
            model: $json['product_name'] ?? null,
        );
        if (is_string($fields['name']['value'] ?? null)) {
            $fields['name']['value'] = $this->trimSiteSuffix($fields['name']['value']);
        }

        // --- description ---
        $fields['description'] = $this->pick(
            jsonld: $productNode['description'] ?? null,
            og: $og['og:description'] ?? null,
            micro: $micro['description'] ?? null,
            model: $json['description'] ?? null,
        );

        // --- product_type ---
        $fields['product_type'] = $this->pick(
            jsonld: $productNode['category'] ?? null,
            og: $og['product:category'] ?? $og['og:type'] ?? null,
            micro: $micro['category'] ?? null,
            model: $json['product_type'] ?? null,
        );

        // --- price + currency (locale-aware) ---
        $fields['price'] = $this->mapPrice($json, $productNode, $og, $warnings);

        // --- main image (lazy/srcset resolved) ---
        $fields['main_image_url'] = $this->mapMainImage($json, $productNode, $og, $micro, $baseUrl, $warnings);

        // --- gallery ---
        $fields['images'] = $this->mapGallery($json, $productNode, $baseUrl);

        // --- variants ---
        $variantResult = $this->variants->map($json['variants'] ?? [], $representation);
        $warnings = array_merge($warnings, $variantResult['warnings']);

        // --- dimensions ---
        $dimensions = $this->mapDimensions($json, $warnings);

        // --- selectors (detected + count-verified) ---
        $detected = $this->selectors->detectAll($representation, $json['selectors'] ?? []);
        $detectedArray = [];
        foreach ($detected as $role => $selector) {
            $detectedArray[$role] = $selector->toArray();
            if ($selector->needsReview) {
                $warnings[] = sprintf(
                    'selector "%s" matches %d elements (needs review)',
                    $role,
                    $selector->matchedCount,
                );
            }
        }

        $confidence = $this->aggregateConfidence($fields, $detected);

        return new MappedProduct(
            fields: $fields,
            variantAxes: $variantResult['axes'],
            variantRows: $variantResult['values'],
            dimensions: $dimensions,
            detectedSelectors: $detectedArray,
            confidence: $confidence,
            raw: [
                'model_json' => $json,
                'model_used' => $result->modelUsed,
                'openrouter_generation_id' => $result->openrouterGenerationId,
                'repaired' => $result->repaired,
                'fetched_via' => $representation->fetchedVia,
                'source_url' => $baseUrl,
            ],
            fetchedVia: $representation->fetchedVia,
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * Pick the highest-trust available source for a scalar field, tagging its
     * provenance + confidence. JSON-LD > OG > microdata > model_inferred.
     *
     * @return array{value: mixed, confidence: float, source: string}
     */
    private function pick(mixed $jsonld, mixed $og, mixed $micro, mixed $model): array
    {
        foreach ([
            [ScanConstants::SOURCE_JSONLD, $jsonld],
            [ScanConstants::SOURCE_OG, $og],
            [ScanConstants::SOURCE_MICRODATA, $micro],
            [ScanConstants::SOURCE_MODEL_INFERRED, $model],
        ] as [$source, $value]) {
            if ($this->present($value)) {
                return [
                    'value' => is_string($value) ? trim($value) : $value,
                    'confidence' => ScanConstants::SOURCE_WEIGHT[$source],
                    'source' => $source,
                ];
            }
        }

        return ['value' => null, 'confidence' => 0.0, 'source' => ScanConstants::SOURCE_MODEL_INFERRED];
    }

    /**
     * Map price + currency. Prefer JSON-LD offers (price + priceCurrency), then
     * og:price, then the model's price/currency. Locale-parse to minor units and
     * lower confidence when the currency was symbol-inferred.
     *
     * @return array<string,mixed>
     */
    private function mapPrice(array $json, ?array $productNode, array $og, array &$warnings): array
    {
        $offer = $this->firstOffer($productNode);

        $rawPrice = null;
        $currencyHint = null;
        $source = ScanConstants::SOURCE_MODEL_INFERRED;

        if ($offer !== null && isset($offer['price'])) {
            $rawPrice = (string) $offer['price'];
            $currencyHint = $offer['priceCurrency'] ?? null;
            $source = ScanConstants::SOURCE_JSONLD;
        } elseif (isset($og['product:price:amount'])) {
            $rawPrice = (string) $og['product:price:amount'];
            $currencyHint = $og['product:price:currency'] ?? null;
            $source = ScanConstants::SOURCE_OG;
        } elseif (isset($json['price']) && $json['price'] !== null) {
            $rawPrice = (string) $json['price'];
            $currencyHint = $json['currency'] ?? null;
            $source = ScanConstants::SOURCE_MODEL_INFERRED;
        }

        if ($rawPrice === null) {
            return ['value' => null, 'currency' => null, 'confidence' => 0.0, 'source' => $source, 'is_range' => false];
        }

        $parsed = $this->money->parse($rawPrice, $currencyHint);

        if ($parsed->currency !== null && $currencyHint === null && $parsed->confidence < 0.7) {
            $warnings[] = 'price currency inferred from symbol — confirm currency';
        }

        if ($parsed->isRange) {
            $warnings[] = 'price is a "from"/range — confirm the variant price basis';
        }

        // Source weight × parse confidence.
        $confidence = ScanConstants::SOURCE_WEIGHT[$source] * max($parsed->confidence, 0.1);

        return [
            'value' => $parsed->minorUnits,
            'currency' => $parsed->currency,
            'is_range' => $parsed->isRange,
            'confidence' => round($confidence, 3),
            'source' => $source,
        ];
    }

    /**
     * Resolve the hero image: JSON-LD image[0] / og:image (cross-checked), else the
     * model's main_image, lazy/srcset resolved + absolutised + placeholder-rejected.
     *
     * @return array{value: ?string, confidence: float, source: string}
     */
    private function mapMainImage(array $json, ?array $productNode, array $og, array $micro, string $baseUrl, array &$warnings): array
    {
        $candidates = [
            [ScanConstants::SOURCE_JSONLD, $this->firstImage($productNode['image'] ?? null)],
            [ScanConstants::SOURCE_OG, $og['og:image'] ?? null],
            [ScanConstants::SOURCE_MICRODATA, $micro['image'] ?? null],
            [ScanConstants::SOURCE_MODEL_INFERRED, $json['main_image'] ?? null],
        ];

        foreach ($candidates as [$source, $candidate]) {
            $resolved = $this->images->resolveUrl($candidate, $baseUrl);

            if ($resolved !== null) {
                return [
                    'value' => $resolved,
                    'confidence' => ScanConstants::SOURCE_WEIGHT[$source],
                    'source' => $source,
                ];
            }

            if ($candidate !== null && $this->present($candidate)) {
                $warnings[] = 'main image candidate was a placeholder — verify the hero image';
            }
        }

        return ['value' => null, 'confidence' => 0.0, 'source' => ScanConstants::SOURCE_MODEL_INFERRED];
    }

    /**
     * @return array{value: array<int,string>, confidence: float, source: string}
     */
    private function mapGallery(array $json, ?array $productNode, string $baseUrl): array
    {
        $urls = [];

        if (isset($productNode['image'])) {
            $urls = array_merge($urls, $this->imageList($productNode['image']));
        }

        foreach ((array) ($json['images'] ?? []) as $img) {
            if (is_string($img)) {
                $urls[] = $img;
            }
        }

        $resolved = $this->images->resolveGallery($urls, $baseUrl);

        return [
            'value' => $resolved,
            'confidence' => $resolved === [] ? 0.0 : 0.7,
            'source' => $productNode !== null ? ScanConstants::SOURCE_JSONLD : ScanConstants::SOURCE_MODEL_INFERRED,
        ];
    }

    /**
     * Map best-effort physical dimensions; flagged clearly when absent (never fabricated).
     *
     * @return array<string,mixed>
     */
    private function mapDimensions(array $json, array &$warnings): array
    {
        $dimensions = (array) ($json['physical_dimensions'] ?? []);

        if ($dimensions === []) {
            $warnings[] = 'no physical dimensions found — best-effort, confirm if needed';
        }

        return $dimensions;
    }

    /** The first offer node from a JSON-LD Product (offers may be a node or a list). */
    private function firstOffer(?array $productNode): ?array
    {
        if ($productNode === null || ! isset($productNode['offers'])) {
            return null;
        }

        $offers = $productNode['offers'];

        if (array_is_list($offers)) {
            return $offers[0] ?? null;
        }

        return is_array($offers) ? $offers : null;
    }

    /** @return array<int,string> */
    private function imageList(mixed $image): array
    {
        if (is_string($image)) {
            return [$image];
        }

        if (is_array($image)) {
            $out = [];
            foreach ($image as $entry) {
                if (is_string($entry)) {
                    $out[] = $entry;
                } elseif (is_array($entry) && isset($entry['url'])) {
                    $out[] = (string) $entry['url'];
                }
            }

            return $out;
        }

        return [];
    }

    private function firstImage(mixed $image): ?string
    {
        $list = $this->imageList($image);

        return $list[0] ?? null;
    }

    /** Strip a "— BrandName" / "| BrandName" suffix from a product title. */
    private function trimSiteSuffix(string $name): string
    {
        return trim(preg_split('/\s+[|–—-]\s+/u', $name)[0] ?? $name);
    }

    private function present(mixed $value): bool
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

    /**
     * The overall scan confidence the threshold reads: the weighted aggregate of
     * the core fields + the selectors. The core fields (name/price/main_image)
     * dominate; a missing critical field drags the aggregate down.
     *
     * @param  array<string,array<string,mixed>>  $fields
     * @param  array<string,\App\Domain\Scan\Selectors\DetectedSelector>  $selectors
     */
    private function aggregateConfidence(array $fields, array $selectors): float
    {
        $coreWeights = [
            'name' => 0.25,
            'price' => 0.2,
            'main_image_url' => 0.2,
            'product_type' => 0.1,
            'description' => 0.05,
        ];

        $score = 0.0;
        $total = 0.0;

        foreach ($coreWeights as $field => $weight) {
            $score += ($fields[$field]['confidence'] ?? 0.0) * $weight;
            $total += $weight;
        }

        // Selectors contribute the remaining 0.2 (averaged across the six roles).
        $selectorScore = 0.0;
        foreach ($selectors as $selector) {
            $selectorScore += $selector->confidence;
        }
        $selectorAvg = $selectors === [] ? 0.0 : $selectorScore / count($selectors);

        $score += $selectorAvg * 0.2;
        $total += 0.2;

        return $total > 0 ? round($score / $total, 3) : 0.0;
    }
}
