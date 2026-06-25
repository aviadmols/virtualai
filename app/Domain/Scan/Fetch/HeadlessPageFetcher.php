<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Ai\ImagePayload;
use App\Domain\Scan\ScanConstants;

/**
 * HeadlessPageFetcher — ATTEMPT 2: the render sidecar for JS-heavy / SPA PDPs.
 *
 * Calls the railway-infra-hosted render service (Playwright/Chromium) over HTTP
 * (SCRAPER_SERVICE_URL + token) and gets back the rendered DOM + a full-page
 * screenshot. We OWN the adapter, not the browser — production points it at the
 * sidecar; tests fake the HTTP call (never a real browser). When the sidecar is
 * disabled/missing, this fails with a clear merchant-facing render_disabled reason
 * so an SPA PDP degrades to manual entry rather than 500ing.
 *
 * The merchant URL is SSRF-guarded before we accept the scan; the sidecar POST
 * itself rides GuardedHttpClient (pinned + stream-capped + no-redirect) via the
 * trusted-internal path so its own egress can't be abused either.
 */
final class HeadlessPageFetcher implements PageFetcher
{
    public function __construct(
        private readonly GuardedHttpClient $client,
    ) {}

    public function fetch(string $url): FetchResult
    {
        UrlGuard::assertFetchable($url);

        if (! $this->enabled()) {
            throw FetchException::failed(ScanConstants::FAIL_RENDER_DISABLED);
        }

        $payload = $this->callSidecar($url);

        $html = (string) ($payload['html'] ?? '');

        if (trim($html) === '') {
            throw FetchException::failed(ScanConstants::FAIL_RENDER_EMPTY);
        }

        return new FetchResult(
            html: $html,
            finalUrl: (string) ($payload['final_url'] ?? $url),
            fetchedVia: ScanConstants::FETCH_VIA_HEADLESS,
            screenshotDataUrl: $this->screenshotDataUrl($payload),
        );
    }

    /** True when the headless sidecar is configured + flagged on. */
    private function enabled(): bool
    {
        return (bool) config('services.scraper.render_enabled', false)
            && (string) config('services.scraper.service_url') !== '';
    }

    /**
     * POST the render request to the sidecar; classify a timeout/transport error.
     *
     * @return array<string,mixed>
     */
    private function callSidecar(string $url): array
    {
        $serviceUrl = rtrim((string) config('services.scraper.service_url'), '/');
        $token = (string) config('services.scraper.service_token');
        $timeout = (int) config('services.scraper.render_timeout', ScanConstants::EGRESS_RENDER_TIMEOUT);
        $userAgent = (string) config('services.scraper.user_agent');

        $body = (string) json_encode([
            'url' => $url,
            'user_agent' => $userAgent,
            'wait_for' => 'networkidle',
            'screenshot' => true,
            'full_page' => true,
        ]);

        $response = $this->client->postJsonInternal(
            $serviceUrl.'/render',
            $body,
            [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
            ScanConstants::EGRESS_MAX_BYTES,
            $timeout,
        );

        if ($response->failed()) {
            throw FetchException::failed(ScanConstants::FAIL_RENDER_EMPTY);
        }

        return (array) (json_decode($response->body, true) ?? []);
    }

    /**
     * The screenshot data URL, only if within the shared image-size ceiling.
     * Coordinated with ImagePayload::MAX_IMAGE_BYTES (5 MiB) so the screenshot
     * can never 413/OOM the downstream model call; an oversize shot is dropped
     * (the cleaned HTML still goes to the model).
     */
    private function screenshotDataUrl(array $payload): ?string
    {
        $base64 = $payload['screenshot_base64'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $decodedBytes = (int) (strlen($base64) * 3 / 4);

        if ($decodedBytes > ImagePayload::MAX_IMAGE_BYTES) {
            return null;
        }

        $mime = (string) ($payload['screenshot_mime'] ?? 'image/png');

        return 'data:'.$mime.';base64,'.$base64;
    }
}
