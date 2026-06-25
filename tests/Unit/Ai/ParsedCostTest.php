<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\ParsedCost;
use PHPUnit\Framework\TestCase;

/**
 * ParsedCost money-path invariant: a null costUsd can NEVER be presented as
 * available. "available" and "non-null cost" are the same thing — the constructor
 * normalizes any contradictory combination to UNAVAILABLE, so a null cost can
 * never reach laravel-backend's charge path (which would TypeError on
 * chargeMicroUsd(null, ...)).
 */
class ParsedCostTest extends TestCase
{
    public function test_inline_factory_yields_available_non_null_cost(): void
    {
        $cost = ParsedCost::inline(0.0123);

        $this->assertTrue($cost->available);
        $this->assertSame(ParsedCost::SOURCE_INLINE, $cost->source);
        $this->assertSame(0.0123, $cost->costUsd);
    }

    public function test_endpoint_factory_yields_available_non_null_cost(): void
    {
        $cost = ParsedCost::fromEndpoint(0.05);

        $this->assertTrue($cost->available);
        $this->assertSame(ParsedCost::SOURCE_ENDPOINT, $cost->source);
        $this->assertSame(0.05, $cost->costUsd);
    }

    public function test_unavailable_factory_is_null_and_not_available(): void
    {
        $cost = ParsedCost::unavailable(40_000);

        $this->assertFalse($cost->available);
        $this->assertNull($cost->costUsd);
        $this->assertSame(ParsedCost::SOURCE_UNAVAILABLE, $cost->source);
        $this->assertSame(40_000, $cost->estimatedCostMicroUsd);
    }

    public function test_null_cost_can_never_be_constructed_as_available(): void
    {
        // The contradictory combination a caller might build directly — a null cost
        // claiming to be available/inline. The constructor MUST collapse it to
        // unavailable so it can never feed the charge path.
        $cost = new ParsedCost(null, true, ParsedCost::SOURCE_INLINE);

        $this->assertFalse($cost->available, 'a null cost must never be available');
        $this->assertNull($cost->costUsd);
        $this->assertSame(ParsedCost::SOURCE_UNAVAILABLE, $cost->source, 'a null cost must report source=unavailable');
    }

    public function test_null_cost_with_endpoint_source_also_normalizes_to_unavailable(): void
    {
        $cost = new ParsedCost(null, true, ParsedCost::SOURCE_ENDPOINT, estimatedCostMicroUsd: 12_000);

        $this->assertFalse($cost->available);
        $this->assertSame(ParsedCost::SOURCE_UNAVAILABLE, $cost->source);
        // The estimate is preserved for reconciliation; it is NOT promoted to cost.
        $this->assertNull($cost->costUsd);
        $this->assertSame(12_000, $cost->estimatedCostMicroUsd);
    }

    public function test_available_always_implies_a_non_null_cost(): void
    {
        // The load-bearing invariant for the money path, asserted over every
        // construction path: available === true => costUsd !== null.
        $candidates = [
            ParsedCost::inline(0.01),
            ParsedCost::fromEndpoint(0.02),
            ParsedCost::unavailable(),
            new ParsedCost(null, true, ParsedCost::SOURCE_INLINE),       // contradictory input
            new ParsedCost(0.03, true, ParsedCost::SOURCE_INLINE),       // valid
            new ParsedCost(null, false, ParsedCost::SOURCE_UNAVAILABLE), // valid unavailable
        ];

        foreach ($candidates as $cost) {
            if ($cost->available) {
                $this->assertNotNull($cost->costUsd, 'an available ParsedCost must carry a non-null cost');
            }
        }
    }
}
