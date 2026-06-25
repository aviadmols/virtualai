<?php

namespace App\Domain\Scan\Contract;

use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Selectors\SelectorVerifier;

/**
 * SelectorReverifier — the backend behind the confirm UI's manual-selector-entry.
 *
 * When a merchant types a raw CSS selector (or uses the element-pick affordance),
 * the UI needs immediate feedback: does it resolve to 1 / 0 / N elements on the
 * live page? This re-fetches the page (HTTP-first) and reports the verdict per the
 * SelectorVerifier. admin-design-system calls this from the review screen; the UI
 * is theirs, the verification is ours.
 */
final class SelectorReverifier
{
    public function __construct(
        private readonly PageSource $fetcher,
    ) {}

    /**
     * Verify one or more merchant-entered selectors against the live page.
     *
     * @param  array<int,string>  $selectors
     * @return array<int,array{selector: string, matched_count: int, resolves_to_one: bool, strategy: string}>
     */
    public function verify(string $url, array $selectors): array
    {
        $fetch = $this->fetcher->fetch($url);
        $verifier = new SelectorVerifier(ScanDom::fromHtml($fetch->html, $fetch->finalUrl));

        return array_map(
            fn (string $selector): array => $verifier->verdict($selector),
            $selectors,
        );
    }

    /**
     * Verify selectors against an already-fetched DOM (no re-fetch) — the cheap
     * path when the review screen still holds the scanned HTML.
     *
     * @param  array<int,string>  $selectors
     * @return array<int,array{selector: string, matched_count: int, resolves_to_one: bool, strategy: string}>
     */
    public function verifyAgainstDom(ScanDom $dom, array $selectors): array
    {
        $verifier = new SelectorVerifier($dom);

        return array_map(
            fn (string $selector): array => $verifier->verdict($selector),
            $selectors,
        );
    }
}
