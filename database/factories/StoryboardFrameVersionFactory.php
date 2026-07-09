<?php

namespace Database\Factories;

use App\Models\StoryboardFrame;
use App\Models\StoryboardFrameVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryboardFrameVersion>
 */
class StoryboardFrameVersionFactory extends Factory
{
    protected $model = StoryboardFrameVersion::class;

    public function definition(): array
    {
        return [
            'frame_id' => StoryboardFrame::factory(),
            'prompt' => 'Cinematic wide pool party, bright daylight',
            'version_number' => 1,
            'is_selected' => true,
        ];
    }
}
