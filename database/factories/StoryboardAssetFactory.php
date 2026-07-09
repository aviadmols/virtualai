<?php

namespace Database\Factories;

use App\Models\StoryboardAsset;
use App\Models\StoryboardProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryboardAsset>
 */
class StoryboardAssetFactory extends Factory
{
    protected $model = StoryboardAsset::class;

    public function definition(): array
    {
        return [
            'project_id' => StoryboardProject::factory(),
            'tag' => 'main_character',
            'type' => StoryboardAsset::TYPE_CHARACTER,
            'file_path' => 'storyboard/inputs/'.$this->faker->uuid().'.png',
            'reference_strength' => 70,
        ];
    }
}
