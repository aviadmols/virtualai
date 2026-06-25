<?php

namespace App\Domain\Scan\Selectors;

use App\Domain\Scan\Represent\PageRepresentation;
use App\Domain\Scan\ScanConstants;

/**
 * SelectorDetector — for each of the six widget roles, build robust CSS selectors,
 * count-verify each against the fetched DOM, score confidence, and emit a primary
 * (best single-match, most-stable) + a fallback chain (distinct resolvers).
 *
 * Candidate sources, best-first:
 *  1. The model's suggested selectors from scan_raw['selectors'][role] (grounded
 *     in the page; the model saw the candidate hints).
 *  2. Selectors synthesised from the candidate hints' stable attributes
 *     (#id, [data-*], [aria-*], [itemprop], semantic tag, .class).
 *
 * A selector that resolves to !=1 element, or is positional-only, is flagged
 * needs_review. add_to_cart is the classic multi-match (sticky+modal+mobile) — a
 * residual >1 match is low-confidence + flagged, never silently the first hit.
 */
final class SelectorDetector
{
    private const MAX_FALLBACK = 3;

    /**
     * Detect a DetectedSelector for every role.
     *
     * @param  array<string,mixed>  $modelSelectors  scan_raw['selectors'] (role => css|list)
     * @return array<string,DetectedSelector>
     */
    public function detectAll(PageRepresentation $representation, array $modelSelectors = []): array
    {
        $verifier = new SelectorVerifier($representation->dom);
        $out = [];

        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $candidates = $this->candidatesFor($role, $representation, $modelSelectors);
            $out[$role] = $this->detect($role, $candidates, $verifier);
        }

        return $out;
    }

    /**
     * Verify + rank the candidate selectors for one role; pick the primary + chain.
     *
     * @param  array<int,string>  $candidates
     */
    public function detect(string $role, array $candidates, SelectorVerifier $verifier): DetectedSelector
    {
        $scored = [];

        foreach (array_unique($candidates) as $selector) {
            if (trim($selector) === '') {
                continue;
            }

            $score = $verifier->score($selector);
            $score['selector'] = $selector;
            $scored[] = $score;
        }

        // Prefer exactly-one matches, then higher confidence, then stable strategy.
        usort($scored, function (array $a, array $b): int {
            $aOne = $a['matched_count'] === 1 ? 1 : 0;
            $bOne = $b['matched_count'] === 1 ? 1 : 0;

            return [$bOne, $b['confidence']] <=> [$aOne, $a['confidence']];
        });

        if ($scored === []) {
            // Nothing resolved — emit a flagged, empty selector (manual entry needed).
            return new DetectedSelector(
                role: $role,
                primary: null,
                fallbackChain: [],
                confidence: 0.0,
                matchedCount: 0,
                strategy: ScanConstants::STRATEGY_POSITIONAL,
                needsReview: true,
            );
        }

        $primary = $scored[0];

        $fallbackChain = [];
        foreach (array_slice($scored, 1) as $candidate) {
            if ($candidate['selector'] === $primary['selector']) {
                continue;
            }

            $fallbackChain[] = $candidate['selector'];

            if (count($fallbackChain) >= self::MAX_FALLBACK) {
                break;
            }
        }

        return new DetectedSelector(
            role: $role,
            primary: $primary['selector'],
            fallbackChain: $fallbackChain,
            confidence: $primary['confidence'],
            matchedCount: $primary['matched_count'],
            strategy: $primary['strategy'],
            needsReview: $primary['needs_review'],
        );
    }

    /**
     * Gather candidate CSS selectors for a role: the model's suggestions first,
     * then synthesised-from-hints.
     *
     * @param  array<string,mixed>  $modelSelectors
     * @return array<int,string>
     */
    private function candidatesFor(string $role, PageRepresentation $representation, array $modelSelectors): array
    {
        $candidates = [];

        // 1. Model suggestions (string or list).
        $suggested = $modelSelectors[$role] ?? null;

        if (is_string($suggested) && trim($suggested) !== '') {
            $candidates[] = $suggested;
        } elseif (is_array($suggested)) {
            foreach ($suggested as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $candidates[] = $entry;
                }
            }
        }

        // 2. Synthesised from the candidate hints' stable attributes.
        $hints = $representation->candidateHints[$role] ?? [];

        foreach ($hints as $hint) {
            foreach ($this->synthesise($hint) as $selector) {
                $candidates[] = $selector;
            }
        }

        return $candidates;
    }

    /**
     * Synthesise stable CSS selectors from a candidate node descriptor, stable
     * first (id > data > aria > itemprop > class > tag).
     *
     * @param  array<string,mixed>  $hint
     * @return array<int,string>
     */
    private function synthesise(array $hint): array
    {
        $out = [];
        $tag = $hint['tag'] ?? '*';

        if (! empty($hint['id'])) {
            $out[] = '#'.$hint['id'];
        }

        foreach ((array) ($hint['data'] ?? []) as $name => $value) {
            $out[] = $tag.'['.$name.'="'.$this->escape((string) $value).'"]';
            $out[] = '['.$name.']';
        }

        foreach ((array) ($hint['aria'] ?? []) as $name => $value) {
            $out[] = $tag.'['.$name.'="'.$this->escape((string) $value).'"]';
        }

        if (! empty($hint['itemprop'])) {
            $out[] = '['.'itemprop="'.$this->escape((string) $hint['itemprop']).'"]';
        }

        if (! empty($hint['classes']) && is_array($hint['classes'])) {
            $first = $hint['classes'][0] ?? null;

            if ($first !== null) {
                $out[] = '.'.$first;
                $out[] = $tag.'.'.$first;
            }
        }

        return $out;
    }

    /** Minimal attribute-value escape for a synthesised selector. */
    private function escape(string $value): string
    {
        return str_replace(['"', '\\'], ['\\"', '\\\\'], $value);
    }
}
