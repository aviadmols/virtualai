<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;

/**
 * PageFetcherManager — the HTTP-first / headless-fallback strategy.
 *
 *   1. SSRF + robots guard, then HttpPageFetcher (cheap, no JS).
 *   2. If RenderDecision says the body is a SPA shell, escalate to the headless
 *      renderer (+ screenshot). If the renderer is disabled/down, keep the HTTP
 *      body when it has SOME content rather than failing the whole scan.
 *   3. A typed FetchException from either path bubbles up to the orchestrator,
 *      which transitions the product to failed with a merchant-facing reason.
 *
 * This class owns only the ORDER + escalation decision; each fetcher owns its
 * transport. Both are injected so tests can fake them.
 */
final class PageFetcherManager implements PageSource
{
    public function __construct(
        private readonly HttpPageFetcher $http,
        private readonly HeadlessPageFetcher $headless,
    ) {}

    public function fetch(string $url): FetchResult
    {
        UrlGuard::assertFetchable($url);

        $httpResult = $this->http->fetch($url);

        if (! RenderDecision::shouldEscalate($httpResult->html)) {
            return $httpResult;
        }

        return $this->escalate($url, $httpResult);
    }

    /**
     * Try the headless renderer; if it is disabled/unavailable, fall back to the
     * HTTP body when it at least has content, else surface the render failure so
     * the merchant gets the manual-entry path.
     */
    private function escalate(string $url, FetchResult $httpResult): FetchResult
    {
        try {
            return $this->headless->fetch($url);
        } catch (FetchException $e) {
            // Renderer disabled/down: a thin-but-nonempty HTTP body is better than
            // nothing — let the model try the cleaned HTML. A truly empty shell
            // re-raises so the scan fails honestly.
            if ($e->reason === ScanConstants::FAIL_RENDER_DISABLED
                && trim(strip_tags($httpResult->html)) !== '') {
                return $httpResult;
            }

            throw $e;
        }
    }
}
