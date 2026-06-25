<?php

namespace App\Domain\Scan\Fetch;

/**
 * FetchResult — the typed outcome of a successful page fetch.
 *
 * Carries the raw HTML, the final (post-redirect) URL the body belongs to, how it
 * was fetched (http | headless), and an optional screenshot reference (base64 data
 * URL) when the headless path captured one. The representation builder consumes
 * this; the model never sees it directly.
 */
final readonly class FetchResult
{
    public function __construct(
        public string $html,
        public string $finalUrl,
        public string $fetchedVia,
        public ?string $screenshotDataUrl = null,
    ) {}

    public function hasScreenshot(): bool
    {
        return $this->screenshotDataUrl !== null;
    }
}
