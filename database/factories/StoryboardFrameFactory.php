<?php

namespace Database\Factories;

use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryboardFrame>
 */
class StoryboardFrameFactory extends Factory
{
    protected $model = StoryboardFrame::class;

    public function definition(): array
    {
        return [
            'project_id' => StoryboardProject::factory(),
            'frame_number' => 1,
            'start_second' => 0,
            'end_second' => 3,
            'description' => 'Wide chaotic pool party shot',
            'image_prompt' => 'Cinematic wide pool party, bright daylight',
            'status' => StoryboardFrame::STATUS_PENDING,
        ];
    }
}
