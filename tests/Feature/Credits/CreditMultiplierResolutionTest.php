<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\CreditMath;
use App\Models\AiOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The markup multiplier is RESOLVED from operation.credit_multiplier ?? config
 * default — NEVER a literal at a call site. This proves the per-operation override
 * beats the 2.5 config default and that a missing override falls back to it.
 */
class CreditMultiplierResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_operation_multiplier_overrides_the_config_default(): void
    {
        $operation = AiOperation::factory()->tryOn()->create(['credit_multiplier' => 3.0]);

        $resolved = CreditMath::multiplierFor($operation);
        $this->assertSame(3.0, $resolved);

        // And the resulting charge uses the override, not the 2.5 default.
        $charge = CreditMath::chargeMicroUsd(0.02, $resolved);
        $this->assertSame(60_000, $charge); // 0.02 × 3 × 1e6
    }

    public function test_null_multiplier_falls_back_to_the_config_default(): void
    {
        $operation = AiOperation::factory()->tryOn()->create(['credit_multiplier' => null]);

        $resolved = CreditMath::multiplierFor($operation);
        $this->assertSame((float) config('trayon.pricing.markup_default'), $resolved);
        $this->assertSame(2.5, $resolved);
    }

    public function test_resolves_by_operation_key_string_too(): void
    {
        AiOperation::factory()->tryOn()->create([
            'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'credit_multiplier' => 1.8,
        ]);

        $this->assertSame(1.8, CreditMath::multiplierFor(AiOperation::KEY_TRY_ON_GENERATION));
    }
}
