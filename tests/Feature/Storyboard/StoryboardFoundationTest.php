<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Storyboard\StoryboardStep;
use App\Models\AiOperation;
use App\Models\StoryboardAsset;
use App\Models\StoryboardFrame;
use App\Models\StoryboardFrameVersion;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use App\Support\GlobalModels;
use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storyboard Phase 1 foundation: the data model + relationships, and that every pipeline step is
 * catalogued as an admin-configurable AiOperation the existing resolver can resolve (no parallel
 * config engine). Also pins that the storyboard tables are GLOBAL (non-tenant) on the allow-list.
 */
class StoryboardFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_children_cascade_on_delete(): void
    {
        $project = StoryboardProject::factory()->create();
        StoryboardAsset::factory()->create(['project_id' => $project->id]);
        $frame = StoryboardFrame::factory()->create(['project_id' => $project->id]);
        StoryboardFrameVersion::factory()->create(['frame_id' => $frame->id]);
        StoryboardStepRun::factory()->create(['project_id' => $project->id]);

        $this->assertSame(1, $project->assets()->count());
        $this->assertSame(1, $project->frames()->count());
        $this->assertSame(1, $frame->versions()->count());
        $this->assertSame(1, $project->stepRuns()->count());

        $project->delete();

        $this->assertDatabaseCount('storyboard_assets', 0);
        $this->assertDatabaseCount('storyboard_frames', 0);
        $this->assertDatabaseCount('storyboard_frame_versions', 0);
        $this->assertDatabaseCount('storyboard_step_runs', 0);
    }

    public function test_expected_frame_count_is_ceil_of_duration_over_interval(): void
    {
        $this->assertSame(5, StoryboardProject::factory()->make(['duration_seconds' => 15, 'frame_interval_seconds' => 3])->expectedFrameCount());
        $this->assertSame(6, StoryboardProject::factory()->make(['duration_seconds' => 16, 'frame_interval_seconds' => 3])->expectedFrameCount());
        $this->assertSame(1, StoryboardProject::factory()->make(['duration_seconds' => 2, 'frame_interval_seconds' => 3])->expectedFrameCount());
    }

    public function test_every_pipeline_step_is_seeded_and_resolvable(): void
    {
        $this->seed(StoryboardPipelineSeeder::class);

        $resolver = app(AiOperationResolver::class);

        foreach (StoryboardStep::ALL as $stepKey) {
            $config = $resolver->for($stepKey);

            $this->assertNotEmpty($config->model, "step {$stepKey} has a default model");
            $this->assertNotNull($config->userPrompt, "step {$stepKey} has a user prompt");
            $this->assertSame(ImageGenerationProvider::PROVIDER_OPENROUTER, $config->provider);
        }
    }

    public function test_text_steps_enforce_a_json_schema_and_the_scene_breakdown_returns_frames(): void
    {
        $this->seed(StoryboardPipelineSeeder::class);
        $resolver = app(AiOperationResolver::class);

        // A text step carries a strict JSON schema; the image step does not.
        $readIdea = $resolver->for(AiOperation::KEY_STORYBOARD_READ_IDEA);
        $this->assertIsArray($readIdea->inputSchema);
        $this->assertSame('object', $readIdea->inputSchema['type']);
        $this->assertStringContainsString('{{story_idea}}', $readIdea->userPrompt);

        $scene = $resolver->for(AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN);
        $this->assertArrayHasKey('frames', $scene->inputSchema['properties']);

        $image = $resolver->for(AiOperation::KEY_STORYBOARD_FRAME_IMAGE);
        $this->assertNull($image->inputSchema);
        $this->assertSame('high', $image->imageQuality);
    }

    public function test_storyboard_models_are_global_and_pinned_on_the_allow_list(): void
    {
        foreach ([
            StoryboardProject::class,
            StoryboardAsset::class,
            StoryboardFrame::class,
            StoryboardFrameVersion::class,
            StoryboardStepRun::class,
        ] as $class) {
            $this->assertTrue(GlobalModels::isGlobal($class), "{$class} must be global (non-tenant)");
        }
    }
}
