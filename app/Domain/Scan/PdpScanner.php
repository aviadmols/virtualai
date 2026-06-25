<?php

namespace App\Domain\Scan;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\ProductScanCaller;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Map\MappedProduct;
use App\Domain\Scan\Map\ProductMapper;
use App\Domain\Scan\Represent\PageRepresentation;
use App\Domain\Scan\Represent\RepresentationBuilder;
use App\Models\AiOperation;
use App\Models\Site;

/**
 * PdpScanner — the scan boundary facade ScanProductJob calls.
 *
 * Two methods:
 *  - represent($url): fetch (HTTP-first, headless fallback) -> clean/trim ->
 *    PageRepresentation (NO model call here);
 *  - extract($representation, $site, $productType): resolve the product_scan bag
 *    from the AiOperationResolver (model + prompt come from the DB, never
 *    hardcoded), call ProductScanCaller (ai-openrouter owns the model call), then
 *    map the strict JSON into the MappedProduct bag with per-field confidence +
 *    verified selectors.
 *
 * This class never calls OpenRouter HTTP, never reads the platform key, never sets
 * scan_status, and never persists — laravel-backend does that with the bag.
 */
final class PdpScanner
{
    public function __construct(
        private readonly PageSource $fetcher,
        private readonly RepresentationBuilder $representationBuilder,
        private readonly AiOperationResolver $resolver,
        private readonly ProductScanCaller $scanCaller,
        private readonly ProductMapper $mapper,
    ) {}

    /**
     * Fetch + build the compact representation. No model call. Throws a typed,
     * merchant-facing FetchException on refusal/failure.
     */
    public function represent(string $url): PageRepresentation
    {
        $fetch = $this->fetcher->fetch($url);

        return $this->representationBuilder->build($fetch);
    }

    /**
     * Run the extraction model call + map. The bag (model + prompt + schema) comes
     * from the resolver — a wrong/missing operation is a loud seeding failure, not
     * a hardcoded fallback. Returns the structured MappedProduct for persistence.
     *
     * @param  array<string,string|int|float|null>  $vars  extra prompt placeholders
     */
    public function extract(
        PageRepresentation $representation,
        ?Site $site = null,
        ?string $productType = null,
        array $vars = [],
    ): MappedProduct {
        $config = $this->resolver->for(AiOperation::KEY_PRODUCT_SCAN, $site, $productType);

        $images = [];
        if ($representation->hasScreenshot()) {
            $images[] = ImagePayload::fromUrl($representation->screenshotDataUrl);
        }

        $result = $this->scanCaller->extract(
            config: $config,
            vars: $this->promptVars($representation, $vars),
            images: $images,
            pageText: $representation->toPromptText(),
        );

        return $this->mapper->map($result, $representation);
    }

    /**
     * Default prompt placeholders from the representation (product_name from the
     * lifted structured data) merged with any caller-supplied vars.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,string|int|float|null>
     */
    private function promptVars(PageRepresentation $representation, array $vars): array
    {
        $product = \App\Domain\Scan\Represent\StructuredData::productNode(
            $representation->structuredData['jsonld'],
        );

        $defaults = [
            'product_name' => $product['name'] ?? ($representation->structuredData['og']['og:title'] ?? ''),
            'source_url' => $representation->sourceUrl,
        ];

        return array_merge($defaults, $vars);
    }
}
