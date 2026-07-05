<?php

namespace App\Domain\Scan\Preview;

use App\Domain\Scan\Represent\ScanDom;

/**
 * PreviewResult — the outcome of a placement-preview fetch.
 *
 * sanitizedHtml is ready to drop into a sandboxed <iframe srcdoc> (scripts/handlers stripped,
 * styles kept, base href + the picker script injected). rawHtml is the untouched fetched page,
 * kept ONLY so a picked selector can be verified server-side against the same DOM the merchant
 * clicked (via dom()); it is never rendered. finalUrl is the post-redirect URL the body belongs
 * to; fetchedVia records http|headless.
 */
final readonly class PreviewResult
{
    public function __construct(
        public string $sanitizedHtml,
        public string $rawHtml,
        public string $finalUrl,
        public string $fetchedVia,
    ) {}

    /** The DOM of the fetched page, for verifying a picked selector (count / resolves-to-one). */
    public function dom(): ScanDom
    {
        return ScanDom::fromHtml($this->rawHtml, $this->finalUrl);
    }
}
