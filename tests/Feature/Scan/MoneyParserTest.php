<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Map\MoneyParser;
use Tests\TestCase;

/**
 * Locale-aware price parsing — the parse that bites. No DB, no network. Proves
 * ILS and EUR parse to the correct minor-units value and decimal-comma vs
 * decimal-point locales are never confused.
 */
class MoneyParserTest extends TestCase
{
    private function parser(): MoneyParser
    {
        return new MoneyParser;
    }

    public function test_ils_shekel_symbol_with_comma_thousands_dot_decimal(): void
    {
        $parsed = $this->parser()->parse('₪1,299.00');

        $this->assertSame('ILS', $parsed->currency);
        $this->assertSame(129_900, $parsed->minorUnits); // 1299.00 ILS = 129900 agorot
        $this->assertFalse($parsed->isRange);
    }

    public function test_european_dot_thousands_comma_decimal_is_not_misparsed(): void
    {
        // 1.299,00 is 1299.00 — the classic mis-parse-to-1.299 scar.
        $parsed = $this->parser()->parse('1.299,00', 'EUR');

        $this->assertSame('EUR', $parsed->currency);
        $this->assertSame(129_900, $parsed->minorUnits);
    }

    public function test_euro_symbol_with_comma_decimal(): void
    {
        $parsed = $this->parser()->parse('€49,95');

        $this->assertSame('EUR', $parsed->currency);
        $this->assertSame(4_995, $parsed->minorUnits);
    }

    public function test_french_space_thousands_comma_decimal(): void
    {
        $parsed = $this->parser()->parse('1 299,00', 'EUR');

        $this->assertSame(129_900, $parsed->minorUnits);
    }

    public function test_en_comma_thousands_dot_decimal(): void
    {
        $parsed = $this->parser()->parse('$1,299.99');

        $this->assertSame(129_999, $parsed->minorUnits);
        // Ambiguous $ → USD default, but flagged low confidence.
        $this->assertSame('USD', $parsed->currency);
        $this->assertLessThan(0.7, $parsed->confidence);
    }

    public function test_explicit_currency_hint_beats_symbol_and_lifts_confidence(): void
    {
        $parsed = $this->parser()->parse('$1,299.99', 'CAD');

        $this->assertSame('CAD', $parsed->currency);
        $this->assertGreaterThan(0.7, $parsed->confidence);
    }

    public function test_from_price_is_flagged_as_range(): void
    {
        $parsed = $this->parser()->parse('From ₪199', null);

        $this->assertTrue($parsed->isRange);
        $this->assertSame('ILS', $parsed->currency);
        $this->assertSame(19_900, $parsed->minorUnits);
    }

    public function test_plain_integer_price(): void
    {
        $parsed = $this->parser()->parse('500', 'ILS');

        $this->assertSame(50_000, $parsed->minorUnits);
    }

    public function test_empty_string_is_unknown(): void
    {
        $parsed = $this->parser()->parse('');

        $this->assertFalse($parsed->isKnown());
        $this->assertNull($parsed->minorUnits);
    }
}
