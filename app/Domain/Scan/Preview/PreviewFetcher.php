<?php

namespace App\Domain\Scan\Preview;

use App\Domain\Scan\Fetch\PageSource;

/**
 * PreviewFetcher — fetch a merchant page for the visual button-placement preview.
 *
 * Reuses the SSRF/egress-guarded scan fetcher (PageSource → PageFetcherManager: HTTP-first,
 * headless fallback with the store's real styles) so the preview never opens a new, unguarded
 * network path. Delegates the safety transform to PreviewSanitizer and returns both the
 * sanitized HTML (for the sandboxed iframe) and the raw HTML (for server-side selector
 * verification). A refused/failed fetch surfaces the same merchant-facing FetchException the
 * scan uses, so the caller can show the manual message instead of a 500.
 */
final class PreviewFetcher
{
    public function __construct(
        private readonly PageSource $pages,
        private readonly PreviewSanitizer $sanitizer,
    ) {}

    /**
     * @throws \App\Domain\Scan\Fetch\FetchException on robots/SSRF refusal, bot-block, timeout, or empty render.
     */
    public function previewFor(string $url, string $pickerScript): PreviewResult
    {
        // fetch() runs UrlGuard::assertFetchable() (SSRF/egress guard) before any network call.
        $fetch = $this->pages->fetch($url);

        return new PreviewResult(
            sanitizedHtml: $this->sanitizer->sanitize($fetch->html, $fetch->finalUrl, $pickerScript),
            rawHtml: $fetch->html,
            finalUrl: $fetch->finalUrl,
            fetchedVia: $fetch->fetchedVia,
        );
    }

    /**
     * Build a preview from ALREADY-FETCHED HTML (the scan snapshot) — no network, no
     * headless render, no SSRF surface. This is the primary path: the page was fetched
     * once at scan time, so the picker renders instantly and reliably from the stored copy.
     */
    public function previewFromHtml(string $rawHtml, string $baseUrl, string $pickerScript): PreviewResult
    {
        return new PreviewResult(
            sanitizedHtml: $this->sanitizer->sanitize($rawHtml, $baseUrl, $pickerScript),
            rawHtml: $rawHtml,
            finalUrl: $baseUrl,
            fetchedVia: 'snapshot',
        );
    }
}
