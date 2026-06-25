<?php

namespace Tests\Unit\Credits;

use App\Domain\Credits\CreditMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CreditMath — the pure markup arithmetic. Integer micro-USD only; the charge is
 * round(cost × multiplier × 1_000_000). No float drift: every case asserts an
 * exact integer, including a fractional cost and the 2.5 default markup.
 */
class CreditMathTest extends TestCase
{
    /**
     * @return array<string,array{0:float,1:float,2:int}>
     */
    public static function chargeCases(): array
    {
        return [
            // cost_usd, multiplier, expected micro-USD selling value
            'one cent at 2.5x' => [0.01, 2.5, 25_000],
            'ten cents at 2.5x' => [0.10, 2.5, 250_000],
            'one dollar at 2.5x' => [1.00, 2.5, 2_500_000],
            'fractional cost at 2.5x' => [0.0375, 2.5, 93_750],
            'per-op override 3x' => [0.02, 3.0, 60_000],
            'per-op override 1.8x' => [0.05, 1.8, 90_000],
            // A cost that would drift as a float: 0.001 × 2.5 = 0.0025 -> 2500 micro.
            'sub-cent rounds exactly' => [0.001, 2.5, 2_500],
            // Rounding boundary: 0.0000004 × 1e6 = 0.4 -> rounds to 0 (half-up at .5).
            'rounds down below half' => [0.0000001, 2.5, 0],
            'rounds up at half' => [0.0000002, 2.5, 1],
        ];
    }

    #[DataProvider('chargeCases')]
    public function test_charge_micro_usd_is_exact(float $costUsd, float $multiplier, int $expected): void
    {
        $this->assertSame($expected, CreditMath::chargeMicroUsd($costUsd, $multiplier));
    }

    public function test_usd_micro_round_trip(): void
    {
        $this->assertSame(5_000_000, CreditMath::usdToMicro(5.0));
        $this->assertSame(2_500_000, CreditMath::usdToMicro(2.5));
        $this->assertSame(5.0, CreditMath::microToUsd(5_000_000));
        $this->assertSame(2.5, CreditMath::microToUsd(2_500_000));
    }

    public function test_returns_an_integer_type_not_a_float(): void
    {
        $value = CreditMath::chargeMicroUsd(0.0333, 2.5);
        $this->assertIsInt($value);
    }
}
