<?php

namespace Database\Factories;

use App\Models\AiOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiOperation>
 */
class AiOperationFactory extends Factory
{
    protected $model = AiOperation::class;

    public function definition(): array
    {
        return [
            'operation_key' => AiOperation::KEY_PRODUCT_SCAN,
            'label' => 'Product Scan',
            'default_model' => 'google/gemini-2.5-flash',
            'fallback_model' => 'openai/gpt-4o-mini',
            'image_quality' => null,
            'aspect_ratio' => null,
            'params' => ['temperature' => 0, 'max_tokens' => 4096],
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => true],
            'retention_days' => 30,
            'estimated_cost_micro_usd' => 3_000,
            'credit_multiplier' => null,
        ];
    }

    public function tryOn(): static
    {
        return $this->state(fn () => [
            'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'label' => 'Try-On Generation',
            'default_model' => 'google/gemini-2.5-flash-image-preview',
            'fallback_model' => 'openai/gpt-image-1',
            'image_quality' => 'high',
            'aspect_ratio' => '3:4',
            'params' => ['seed' => 42, 'temperature' => 0.2],
            'input_schema' => null,
            'estimated_cost_micro_usd' => 40_000,
        ]);
    }
}
