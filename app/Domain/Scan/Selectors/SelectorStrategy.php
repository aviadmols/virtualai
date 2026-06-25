<?php

namespace App\Domain\Scan\Selectors;

use App\Domain\Scan\ScanConstants;

/**
 * SelectorStrategy — classify a CSS selector by stability so the confidence score
 * can weight stable selectors (id/data/aria/semantic) over brittle ones (class,
 * positional nth-child). A positional selector is always the most fragile and is
 * flagged for review even when it currently resolves to one element.
 */
final class SelectorStrategy
{
    // === CONSTANTS ===
    private const SEMANTIC_TAGS = ['h1', 'main', 'article', 'header', 'figure', 'button'];

    /** The dominant strategy of a CSS selector (its weakest link governs). */
    public static function classify(string $selector): string
    {
        $selector = trim($selector);

        // Positional is the weakest signal — if present anywhere, it governs.
        if (self::isPositional($selector)) {
            return ScanConstants::STRATEGY_POSITIONAL;
        }

        if (str_contains($selector, '#') || preg_match('/\[id[~|^$*]?=/', $selector) === 1) {
            return ScanConstants::STRATEGY_ID;
        }

        if (preg_match('/\[data-[\w-]+/', $selector) === 1) {
            return ScanConstants::STRATEGY_DATA_ATTR;
        }

        if (preg_match('/\[aria-[\w-]+/', $selector) === 1) {
            return ScanConstants::STRATEGY_ARIA;
        }

        if (preg_match('/\[itemprop/', $selector) === 1) {
            return ScanConstants::STRATEGY_ITEMPROP;
        }

        if (str_contains($selector, '.')) {
            return ScanConstants::STRATEGY_CLASS;
        }

        if (self::isSemantic($selector)) {
            return ScanConstants::STRATEGY_SEMANTIC;
        }

        // A bare tag-name chain with no anchor is effectively positional-grade.
        return ScanConstants::STRATEGY_CLASS;
    }

    /** True for nth-child / nth-of-type / descendant-by-position chains. */
    private static function isPositional(string $selector): bool
    {
        if (preg_match('/:nth-(child|of-type|last-child)/i', $selector) === 1) {
            return true;
        }

        // A deep bare-tag chain (e.g. "div > div > span") with no id/class/attr.
        if (preg_match('/>/', $selector) === 1
            && preg_match('/[#.\[]/', $selector) === 0) {
            return true;
        }

        return false;
    }

    private static function isSemantic(string $selector): bool
    {
        $head = strtolower(trim(explode(' ', $selector)[0]));
        $head = preg_replace('/[:\[].*$/', '', $head) ?? $head;

        return in_array($head, self::SEMANTIC_TAGS, true);
    }

    /** The confidence weight for a strategy (stable high, positional low). */
    public static function weight(string $strategy): float
    {
        return ScanConstants::STRATEGY_WEIGHT[$strategy] ?? ScanConstants::STRATEGY_WEIGHT[ScanConstants::STRATEGY_CLASS];
    }

    /** Positional selectors are always flagged for human review. */
    public static function alwaysReview(string $strategy): bool
    {
        return $strategy === ScanConstants::STRATEGY_POSITIONAL;
    }
}
