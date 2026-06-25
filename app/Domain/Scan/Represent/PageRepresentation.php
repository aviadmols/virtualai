<?php

namespace App\Domain\Scan\Represent;

/**
 * PageRepresentation — the compact, hint-annotated payload ai-openrouter consumes.
 *
 * NOT raw full HTML (that blows the token budget and buries the signal). Carries:
 *  - cleanedHtml: scripts/styles/SVG/comments/chrome stripped, structure + product
 *    nodes kept, capped to the token budget;
 *  - structuredData: the lifted JSON-LD / OG / microdata (highest-confidence);
 *  - candidateHints: per selector-role candidate nodes with stable attributes;
 *  - screenshotDataUrl: the headless full-page screenshot (the model's safety net);
 *  - sourceUrl + fetchedVia: provenance.
 *
 * It also keeps a live ScanDom over the ORIGINAL fetched DOM so the selector layer
 * can count-verify selectors against the real page (not the trimmed copy).
 */
final readonly class PageRepresentation
{
    /**
     * @param  array{jsonld: array<int,array<string,mixed>>, og: array<string,string>, microdata: array<string,string>}  $structuredData
     * @param  array<string,array<int,array<string,mixed>>>  $candidateHints
     */
    public function __construct(
        public string $cleanedHtml,
        public array $structuredData,
        public array $candidateHints,
        public string $sourceUrl,
        public string $fetchedVia,
        public ?string $screenshotDataUrl,
        public ScanDom $dom,
    ) {}

    public function hasScreenshot(): bool
    {
        return $this->screenshotDataUrl !== null;
    }

    /**
     * The text block appended to the model's user prompt: the lifted structured
     * data + candidate hints + the cleaned HTML, in trust order. Compact, JSON-ish.
     */
    public function toPromptText(): string
    {
        $parts = [];

        $product = StructuredData::productNode($this->structuredData['jsonld']);

        if ($product !== null) {
            $parts[] = "STRUCTURED PRODUCT (schema.org/Product, high confidence):\n"
                .json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($this->structuredData['og'] !== []) {
            $parts[] = "OPEN GRAPH:\n".json_encode($this->structuredData['og'], JSON_UNESCAPED_SLASHES);
        }

        if ($this->candidateHints !== []) {
            $parts[] = "CANDIDATE SELECTOR HINTS (role -> nodes):\n"
                .json_encode($this->candidateHints, JSON_UNESCAPED_SLASHES);
        }

        $parts[] = "PAGE HTML (cleaned):\n".$this->cleanedHtml;

        return implode("\n\n", $parts);
    }
}
