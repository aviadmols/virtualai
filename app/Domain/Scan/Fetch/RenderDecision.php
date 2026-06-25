<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;

/**
 * RenderDecision — the small, tested heuristic that decides whether a raw HTTP
 * body is "rendered enough" to extract from, or whether it is a JS-heavy / SPA
 * shell that must escalate to the headless renderer.
 *
 * The HTTP body looks RENDERED when it carries a real product signal (a JSON-LD
 * Product node, an og:image, a price-shaped token, or an <h1>) AND enough visible
 * text. It looks like a SPA SHELL when it has a framework root marker (id="root"/
 * "app"/"__next") with almost no visible text and no product signal — those
 * escalate. Pure + deterministic so the heuristic is unit-testable without a fetch.
 */
final class RenderDecision
{
    /** True when the raw HTTP HTML is rich enough to skip the headless renderer. */
    public static function looksRendered(string $html): bool
    {
        $textDensity = self::visibleTextLength($html);
        $hasProductSignal = self::hasProductSignal($html);

        // Strong signal + any reasonable text → trust HTTP.
        if ($hasProductSignal && $textDensity >= ScanConstants::MIN_TEXT_DENSITY_CHARS) {
            return true;
        }

        // A framework shell with no product node and little text → escalate.
        if (self::isSpaShell($html) && ! $hasProductSignal) {
            return false;
        }

        // No product signal at all and thin text → escalate to be safe.
        if (! $hasProductSignal && $textDensity < ScanConstants::MIN_TEXT_DENSITY_CHARS) {
            return false;
        }

        // Product signal present but thin text (server-rendered minimal PDP) → trust HTTP.
        return $hasProductSignal;
    }

    /** Inverse of looksRendered: escalate to headless when the body is a shell. */
    public static function shouldEscalate(string $html): bool
    {
        return ! self::looksRendered($html);
    }

    /** A product signal: JSON-LD Product, og:image, an <h1>, or a price-shaped token. */
    private static function hasProductSignal(string $html): bool
    {
        if (stripos($html, ScanConstants::JSONLD_TYPE) !== false
            && stripos($html, 'product') !== false) {
            return true;
        }

        if (stripos($html, 'og:image') !== false || stripos($html, 'product:price') !== false) {
            return true;
        }

        if (preg_match('/<h1[\s>]/i', $html) === 1) {
            return true;
        }

        // A price-shaped token (currency symbol/code next to digits).
        if (preg_match('/(?:[$€£₪]|USD|EUR|ILS|GBP)\s?\d/u', $html) === 1) {
            return true;
        }

        return false;
    }

    /** A framework root marker with thin body content marks a SPA shell. */
    private static function isSpaShell(string $html): bool
    {
        foreach (ScanConstants::SPA_ROOT_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Approximate visible text length: strip tags + scripts/styles, collapse space. */
    private static function visibleTextLength(string $html): int
    {
        $noScript = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($noScript)) ?? '');

        return mb_strlen($text);
    }
}
