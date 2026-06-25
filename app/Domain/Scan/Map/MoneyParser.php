<?php

namespace App\Domain\Scan\Map;

/**
 * MoneyParser — locale-aware price + currency parsing (the parse that bites).
 *
 * The scar: `₪1.299,00` naively read as `1.299`. The fix: detect the CURRENCY
 * first (ISO code / symbol / explicit hint), then detect the NUMBER LOCALE from
 * the grouping/decimal punctuation (never assume `.` is the decimal point), then
 * parse to integer MINOR units. Handles thousands separators, decimal-comma vs
 * decimal-point, NBSP/space grouping, "from" ranges, and symbol-vs-ISO currency.
 *
 * Confidence drops when the currency came only from an ambiguous symbol ($ could
 * be USD/CAD/AUD) or the decimal separator had to be guessed.
 */
final class MoneyParser
{
    // === CONSTANTS ===
    // Unambiguous symbol -> ISO. `$` is deliberately ABSENT (ambiguous; resolved
    // by an explicit code or a low-confidence USD default).
    private const SYMBOL_TO_ISO = [
        '₪' => 'ILS',
        '€' => 'EUR',
        '£' => 'GBP',
        '¥' => 'JPY',
        '₩' => 'KRW',
        '₹' => 'INR',
    ];

    private const ISO_CODES = ['USD', 'EUR', 'GBP', 'ILS', 'JPY', 'CAD', 'AUD', 'CHF', 'INR', 'KRW', 'NIS'];

    // "from"/range markers across EN + HE.
    private const RANGE_MARKERS = ['from', 'starting at', 'as low as', 'מ-', 'החל מ'];

    /**
     * Parse a raw price string, optionally with currency hints from JSON-LD/OG.
     *
     * @param  string  $raw  the price text (may carry a symbol/code)
     * @param  string|null  $currencyHint  an explicit ISO code (JSON-LD priceCurrency / og:price:currency)
     * @param  string|null  $localeHint  a site locale (e.g. "he-IL") to disambiguate
     */
    public function parse(string $raw, ?string $currencyHint = null, ?string $localeHint = null): ParsedMoney
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ParsedMoney::unknown();
        }

        $isRange = $this->looksLikeRange($raw);

        [$currency, $currencyConfidence] = $this->detectCurrency($raw, $currencyHint);

        $number = $this->extractNumber($raw);

        if ($number === null) {
            return new ParsedMoney(null, $currency, $isRange, $currency !== null ? 0.3 : 0.0);
        }

        [$amount, $localeConfidence] = $this->parseNumber($number);

        if ($amount === null) {
            return new ParsedMoney(null, $currency, $isRange, 0.2);
        }

        $minor = (int) round($amount * 100);

        $confidence = min($currencyConfidence, $localeConfidence);

        if ($isRange) {
            $confidence *= 0.8; // a range is captured but the merchant confirms the basis
        }

        return new ParsedMoney($minor, $currency, $isRange, $confidence);
    }

    /** True when the raw price is a "from"/range price (variant-dependent). */
    private function looksLikeRange(string $raw): bool
    {
        $lower = mb_strtolower($raw);

        foreach (self::RANGE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        // Two prices joined by a dash (e.g. "199 - 249").
        return preg_match('/\d[\s\xc2\xa0]*[-–—][\s\xc2\xa0]*\d/u', $raw) === 1;
    }

    /**
     * Detect the currency: explicit hint (ISO) > unambiguous symbol > ISO code in
     * the string > ambiguous `$` (low-confidence USD default).
     *
     * @return array{0: string|null, 1: float}
     */
    private function detectCurrency(string $raw, ?string $currencyHint): array
    {
        if ($currencyHint !== null && trim($currencyHint) !== '') {
            $iso = strtoupper(trim($currencyHint));

            return [$this->normaliseIso($iso), 1.0];
        }

        foreach (self::SYMBOL_TO_ISO as $symbol => $iso) {
            if (str_contains($raw, $symbol)) {
                return [$iso, 0.9];
            }
        }

        $upper = strtoupper($raw);

        foreach (self::ISO_CODES as $code) {
            if (preg_match('/\b'.$code.'\b/', $upper) === 1) {
                return [$this->normaliseIso($code), 0.85];
            }
        }

        if (str_contains($raw, '$')) {
            // Ambiguous: USD/CAD/AUD/etc. Default USD but flag with low confidence.
            return ['USD', 0.5];
        }

        return [null, 0.4];
    }

    /** NIS is a colloquial alias for ILS; normalise. */
    private function normaliseIso(string $iso): string
    {
        return $iso === 'NIS' ? 'ILS' : $iso;
    }

    /** Pull the first number-shaped token (digits + grouping/decimal punctuation). */
    private function extractNumber(string $raw): ?string
    {
        // Match digits with optional . , space and NBSP separators.
        if (preg_match('/\d[\d.,\s\x{00A0}\x{202F}]*\d|\d/u', $raw, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * Parse a number token to a float by detecting its decimal separator from the
     * punctuation layout — never assuming `.` is decimal.
     *
     *   1,299.00  -> 1299.00 (en: comma groups, dot decimal)
     *   1.299,00  -> 1299.00 (de/he-ish: dot groups, comma decimal)
     *   1 299,00  -> 1299.00 (fr: space groups, comma decimal)
     *   1299      -> 1299
     *
     * @return array{0: float|null, 1: float}  [amount, localeConfidence]
     */
    private function parseNumber(string $number): array
    {
        // Normalise NBSP / narrow-NBSP / spaces (always grouping, never decimal).
        $n = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $number) ?? $number;

        $hasComma = str_contains($n, ',');
        $hasDot = str_contains($n, '.');

        if ($hasComma && $hasDot) {
            // Whichever separator appears LAST is the decimal separator.
            $lastComma = strrpos($n, ',');
            $lastDot = strrpos($n, '.');

            if ($lastComma > $lastDot) {
                // comma is decimal: dots are grouping.
                $normalised = str_replace('.', '', $n);
                $normalised = str_replace(',', '.', $normalised);
            } else {
                // dot is decimal: commas are grouping.
                $normalised = str_replace(',', '', $n);
            }

            return [(float) $normalised, 0.95];
        }

        if ($hasComma) {
            // Only a comma: decimal if it has exactly 2 trailing digits, else grouping.
            if (preg_match('/,\d{2}$/', $n) === 1 && preg_match('/,\d{3}(?:\D|$)/', $n) === 0) {
                return [(float) str_replace(',', '.', $n), 0.85];
            }

            // Comma as a thousands separator (e.g. 1,299).
            return [(float) str_replace(',', '', $n), 0.85];
        }

        if ($hasDot) {
            // Only a dot: decimal if exactly 2 trailing digits OR a single dot;
            // grouping if it looks like 1.299 (3 trailing digits, no decimals).
            if (preg_match('/\.\d{3}$/', $n) === 1 && substr_count($n, '.') === 1
                && preg_match('/\.\d{2}$/', $n) === 0) {
                // 1.299 -> ambiguous; treat as grouping (de-style thousands) with lower confidence.
                return [(float) str_replace('.', '', $n), 0.6];
            }

            return [(float) $n, 0.9];
        }

        // Pure integer.
        return [(float) $n, 0.95];
    }
}
