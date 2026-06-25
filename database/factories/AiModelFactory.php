<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiModel>
 */
class AiModelFactory extends Factory
{
    protected $model = AiModel::class;

    public function definition(): array
    {
        return [
            'operation_key' => AiOperation::KEY_PRODUCT_SCAN,
            'model_id' => 'google/gemini-2.5-flash',
            'label' => 'Gemini 2.5 Flash',
            'is_default' => true,
            'is_fallback' => false,
            'cost_hint_micro_usd' => 3_000,
            'cost_unit' => AiModel::UNIT_PER_1K_TOKENS,
            'is_active' => true,
        ];
    }

    public function fallback(string $modelId): static
    {
        return $this->state(fn () => [
            'model_id' => $modelId,
            'is_default' => false,
            'is_fallback' => true,
        ]);
    }
}
