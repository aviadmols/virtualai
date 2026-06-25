<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;

/**
 * HttpPageFetcher — ATTEMPT 1: a cheap server-side HTTP GET (no JS).
 *
 * Every byte of this fetch rides GuardedHttpClient, which RESOLVES + VALIDATES the
 * host (refusing any private/metadata IP), PINS the connection to the validated IP
 * (no DNS rebinding), STREAMS the body with a mid-stream byte cap (no OOM), and
 * re-guards each redirect hop (a 302 → internal IP is refused). This class adds the
 * scan-specific policy on top: robots.txt, bot-challenge detection, and the typed,
 * merchant-facing failure reasons — never a bare 500, never a silent empty scan.
 */
final class HttpPageFetcher implements PageFetcher
{
    // === CONSTANTS ===
    private const BOT_CHALLENGE_MARKERS = [
        'cf-browser-verification',
        'cf-challenge',
        'just a moment',
        '_Incapsula_',
        'perimeterx',
        'px-captcha',
        'access denied',
        'enable javascript and cookies to continue',
    ];

    private const BLOCK_STATUS = [401, 403, 429, 503];

    public function __construct(
        private readonly GuardedHttpClient $client,
        private readonly RobotsPolicy $robots,
    ) {}

    public function fetch(string $url): FetchResult
    {
        UrlGuard::assertFetchable($url);

        if (config('services.scraper.respect_robots', true) && ! $this->robots->allows($url)) {
            throw FetchException::refused(ScanConstants::FAIL_ROBOTS_BLOCKED);
        }

        $response = $this->client->get(
            $url,
            $this->headers(),
            (int) config('services.scraper.max_bytes', ScanConstants::EGRESS_MAX_BYTES),
            (int) config('services.scraper.http_timeout', ScanConstants::EGRESS_HTTP_TIMEOUT),
            (int) config('services.scraper.max_redirects', ScanConstants::EGRESS_MAX_REDIRECTS),
        );

        $this->assertNotBlocked($response);
        $this->assertHasContent($response->body);

        return new FetchResult(
            html: $response->body,
            finalUrl: $response->finalUrl,
            fetchedVia: ScanConstants::FETCH_VIA_HTTP,
        );
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'User-Agent' => (string) config('services.scraper.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en,he;q=0.8',
        ];
    }

    /** A block-status or a known bot-challenge body fails with a clear reason. */
    private function assertNotBlocked(GuardedResponse $response): void
    {
        if (in_array($response->status, self::BLOCK_STATUS, true)) {
            throw FetchException::failed(ScanConstants::FAIL_BOT_BLOCKED);
        }

        if ($response->failed()) {
            throw FetchException::failed(ScanConstants::FAIL_HTTP_ERROR);
        }

        $lower = strtolower($response->body);

        foreach (self::BOT_CHALLENGE_MARKERS as $marker) {
            if (str_contains($lower, strtolower($marker))) {
                throw FetchException::failed(ScanConstants::FAIL_BOT_BLOCKED);
            }
        }
    }

    /** An empty body fails with render_empty (the SPA-shell signal handled upstream). */
    private function assertHasContent(string $body): void
    {
        if (trim($body) === '') {
            throw FetchException::failed(ScanConstants::FAIL_RENDER_EMPTY);
        }
    }
}
