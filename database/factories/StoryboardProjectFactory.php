<?php

namespace Database\Factories;

use App\Models\StoryboardProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryboardProject>
 */
class StoryboardProjectFactory extends Factory
{
    protected $model = StoryboardProject::class;

    public function definition(): array
    {
        return [
            'title' => 'Pool Party Trailer',
            'story_idea' => 'A realistic cinematic pool party trailer using @location_pool as the location.',
            'genre' => 'cinematic comedy trailer',
            'duration_seconds' => 15,
            'frame_interval_seconds' => 3,
            'aspect_ratio' => '16:9',
            'status' => StoryboardProject::STATUS_DRAFT,
        ];
    }
}
