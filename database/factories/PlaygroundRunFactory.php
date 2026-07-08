<?php

namespace Database\Factories;

use App\Models\PlaygroundRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundRun>
 */
class PlaygroundRunFactory extends Factory
{
    protected $model = PlaygroundRun::class;

    public function definition(): array
    {
        return [
            'kind' => PlaygroundRun::KIND_IMAGE,
            'provider' => PlaygroundRun::PROVIDER_BYTEPLUS,
            'model_id' => 'seedream-5-0-260128',
            'prompt' => 'a red silk dress on a studio model, soft light',
            'input_paths' => [],
            'status' => PlaygroundRun::STATUS_QUEUED,
        ];
    }

    public function video(): static
    {
        return $this->state(fn (): array => [
            'kind' => PlaygroundRun::KIND_VIDEO,
            'provider' => PlaygroundRun::PROVIDER_BYTEPLUS,
            'model_id' => 'dreamina-seedance-2-0-260128',
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => ['status' => PlaygroundRun::STATUS_RUNNING]);
    }
}
