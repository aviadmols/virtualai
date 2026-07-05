<?php

namespace App\Domain\Scan\Represent;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\ScanConstants;

/**
 * RepresentationBuilder — turn a raw FetchResult into the compact PageRepresentation
 * the model consumes.
 *
 * Cleaning order (token budget is a design constraint):
 *  1. Lift JSON-LD / OG / microdata BEFORE stripping (the JSON-LD scripts are gold).
 *  2. Strip noise subtrees (script/style/svg/noscript/iframe), HTML comments, and
 *     obvious chrome (nav/header/footer/cookie banners) — keep structure + product.
 *  3. Build candidate-selector hints from the ORIGINAL DOM.
 *  4. Collapse whitespace and cap to REPRESENTATION_MAX_CHARS (drop the tail, which
 *     is marketing copy, before the head, which holds the product node).
 */
final class RepresentationBuilder
{
    // === CONSTANTS ===
    // Chrome regions stripped wholesale once their structure is captured. Removed
    // by tag so the product node (typically <main>/<article>) survives.
    private const CHROME_TAGS = ['nav', 'header', 'footer', 'aside'];

    public function build(FetchResult $fetch): PageRepresentation
    {
        $html = $fetch->html;
        $baseUrl = $fetch->finalUrl;

        // 1. Lift structured data from the ORIGINAL html (before stripping).
        $structured = StructuredData::lift($html);

        // The DOM used for selector verification + candidate hints is the original.
        $dom = ScanDom::fromHtml($html, $baseUrl);

        // 2. Candidate hints from the real DOM.
        $hints = CandidateHintBuilder::build($dom);

        // 3. Clean + trim for the model.
        $cleaned = $this->clean($html);
        $cleaned = $this->cap($cleaned);

        return new PageRepresentation(
            cleanedHtml: $cleaned,
            structuredData: $structured,
            candidateHints: $hints,
            sourceUrl: $baseUrl,
            fetchedVia: $fetch->fetchedVia,
            screenshotDataUrl: $fetch->screenshotDataUrl,
            dom: $dom,
            rawHtml: $html,
        );
    }

    /** Strip noise subtrees, comments, and chrome; collapse whitespace. */
    private function clean(string $html): string
    {
        // Drop noise + chrome subtrees by tag.
        foreach ([...ScanConstants::NOISE_TAGS, ...self::CHROME_TAGS] as $tag) {
            $html = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', ' ', $html) ?? $html;
            // Self-closing / unclosed variants.
            $html = preg_replace('#<'.$tag.'\b[^>]*/?>#is', ' ', $html) ?? $html;
        }

        // HTML comments.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // Collapse runs of whitespace.
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;

        return trim($html);
    }

    /**
     * Cap to the token budget. Prefer the head (product node) over the long tail
     * of marketing copy; a hard cut is acceptable because the structured-data
     * block + candidate hints carry the high-signal fields regardless.
     */
    private function cap(string $cleaned): string
    {
        if (mb_strlen($cleaned) <= ScanConstants::REPRESENTATION_MAX_CHARS) {
            return $cleaned;
        }

        return mb_substr($cleaned, 0, ScanConstants::REPRESENTATION_MAX_CHARS);
    }
}
