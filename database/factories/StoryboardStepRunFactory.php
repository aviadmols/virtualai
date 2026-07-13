<?php

namespace Database\Factories;

use App\Models\AiOperation;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryboardStepRun>
 */
class StoryboardStepRunFactory extends Factory
{
    protected $model = StoryboardStepRun::class;

    public function definition(): array
    {
        return [
            'project_id' => StoryboardProject::factory(),
            'step_key' => AiOperation::KEY_STORYBOARD_STORY_DIRECTOR,
            'status' => StoryboardStepRun::STATUS_PENDING,
        ];
    }
}
