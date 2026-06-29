<?php

namespace App\Domain\Scan\Review;

use App\Domain\Scan\Contract\SelectorReverifier;
use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Selectors\SelectorStrategy;

/**
 * SelectorTester — the selector-test contract entry point for the A4 review form.
 *
 * Wraps the existing SelectorReverifier (which owns the page re-fetch + the
 * SelectorVerifier count) and lifts its raw verdict into the typed
 * SelectorTestResult the per-selector "Test selector" action returns. Two paths,
 * same typed result:
 *  - testAgainstDom(): cheap — the review screen still holds the scanned HTML;
 *  - testAgainstLivePage(): re-fetches via the guarded Fetch strategy. A fetch
 *    refusal/failure becomes an OUTCOME_ERROR (never a 500), carrying the
 *    merchant-facing reason so the UI can show what went wrong.
 *
 * The pure count→outcome mapping lives in SelectorTestResult; this class is just
 * the wiring so the matcher stays unit-testable without a network.
 */
final readonly class SelectorTester
{
    public function __construct(
        private SelectorReverifier $reverifier,
    ) {}

    /**
     * Test selectors against an already-fetched DOM — no network. The unit-testable
     * path: hand it a ScanDom and a list of selectors, get typed results back.
     *
     * @param  array<int,string>  $selectors
     * @return array<int,SelectorTestResult>
     */
    public function testAgainstDom(ScanDom $dom, array $selectors): array
    {
        $verdicts = $this->reverifier->verifyAgainstDom($dom, $selectors);

        return array_map(
            fn (array $verdict): SelectorTestResult => SelectorTestResult::fromCount(
                $verdict['selector'],
                $verdict['matched_count'],
                $verdict['strategy'],
            ),
            $verdicts,
        );
    }

    /**
     * Test selectors by re-fetching the live page (guarded Fetch strategy). A fetch
     * refusal/failure is reported as an OUTCOME_ERROR per selector with the
     * merchant-facing reason — never thrown up as a 500.
     *
     * @param  array<int,string>  $selectors
     * @return array<int,SelectorTestResult>
     */
    public function testAgainstLivePage(string $url, array $selectors): array
    {
        try {
            $verdicts = $this->reverifier->verify($url, $selectors);
        } catch (FetchException $e) {
            return array_map(
                fn (string $selector): SelectorTestResult => SelectorTestResult::error(
                    $selector,
                    SelectorStrategy::classify($selector),
                    $e->reason,
                ),
                $selectors,
            );
        }

        return array_map(
            fn (array $verdict): SelectorTestResult => SelectorTestResult::fromCount(
                $verdict['selector'],
                $verdict['matched_count'],
                $verdict['strategy'],
            ),
            $verdicts,
        );
    }
}
