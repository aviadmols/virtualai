<?php

namespace App\Domain\Scan\Fetch;

/**
 * PageSource — the seam the scan orchestrator depends on for "give me the page".
 *
 * PageFetcherManager is the production implementation (HTTP-first, headless
 * fallback). Depending on this interface (not the concrete manager) lets the
 * orchestrator + the confirm-time re-verifier be tested with a fake source — no
 * network, no real browser — while the production classes stay final.
 */
interface PageSource
{
    /**
     * @throws FetchException on robots/SSRF refusal, bot-block, timeout, or empty render.
     */
    public function fetch(string $url): FetchResult;
}
