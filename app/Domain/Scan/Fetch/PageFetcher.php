<?php

namespace App\Domain\Scan\Fetch;

/**
 * PageFetcher — the pluggable fetch strategy seam.
 *
 * Implementations: HttpPageFetcher (cheap, no JS) and HeadlessPageFetcher (the
 * render sidecar for SPA PDPs). The manager picks HTTP first and escalates to
 * headless via the RenderDecision heuristic. Every implementation throws a typed,
 * merchant-facing FetchException on refusal/failure — never a bare 500.
 */
interface PageFetcher
{
    /**
     * Fetch the page at $url, returning the raw HTML (+ optional screenshot).
     *
     * @throws FetchException on robots/SSRF refusal, bot-block, timeout, or empty render.
     */
    public function fetch(string $url): FetchResult;
}
