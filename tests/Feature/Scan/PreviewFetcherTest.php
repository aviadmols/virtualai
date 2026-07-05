<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Preview\PreviewFetcher;
use App\Domain\Scan\Preview\PreviewSanitizer;
use Tests\TestCase;

/**
 * PreviewFetcher fetches through the guarded PageSource (no new network path), sanitizes the
 * result for the iframe, and keeps the raw HTML so a picked selector verifies against the exact
 * DOM the merchant saw. A fake PageSource stands in for the real HTTP/headless fetcher.
 */
class PreviewFetcherTest extends TestCase
{
    public function test_it_sanitizes_the_page_and_retains_the_raw_dom_for_verification(): void
    {
        $raw = '<html><head></head><body><script>evil()</script><div id="buy">Buy</div></body></html>';

        $source = new class($raw) implements PageSource {
            public function __construct(private readonly string $raw) {}

            public function fetch(string $url): FetchResult
            {
                return new FetchResult(html: $this->raw, finalUrl: 'https://shop.example/p', fetchedVia: 'http');
            }
        };

        $preview = (new PreviewFetcher($source, new PreviewSanitizer()))
            ->previewFor('https://shop.example/p', '/*P*/1;');

        // Sanitized for the iframe; raw kept for verification.
        $this->assertStringNotContainsString('evil()', $preview->sanitizedHtml);
        $this->assertStringContainsString('evil()', $preview->rawHtml);
        $this->assertSame('https://shop.example/p', $preview->finalUrl);

        // A picked selector verifies against the same DOM the merchant clicked.
        $this->assertTrue($preview->dom()->matchesExactlyOne('#buy'));
        $this->assertSame(0, $preview->dom()->count('#missing'));
    }
}
