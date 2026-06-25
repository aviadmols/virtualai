<?php

namespace App\Domain\Scan\Map;

/**
 * ParsedMoney — a locale-aware money parse result.
 *
 * minorUnits is the integer amount in the currency's minor unit (cents/agorot) —
 * NEVER a lossy float. currency is the ISO-4217 code. isRange flags a "from"/range
 * price. confidence is lower when the currency was inferred only from an ambiguous
 * symbol or the number locale had to be guessed.
 */
final readonly class ParsedMoney
{
    public function __construct(
        public ?int $minorUnits,
        public ?string $currency,
        public bool $isRange,
        public float $confidence,
        public ?string $source = null,
    ) {}

    public static function unknown(): self
    {
        return new self(null, null, false, 0.0);
    }

    public function isKnown(): bool
    {
        return $this->minorUnits !== null;
    }

    /** The amount as a decimal string (display only; storage uses minorUnits). */
    public function toDecimalString(): ?string
    {
        if ($this->minorUnits === null) {
            return null;
        }

        return number_format($this->minorUnits / 100, 2, '.', '');
    }
}
