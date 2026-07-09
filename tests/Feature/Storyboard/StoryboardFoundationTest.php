<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Storyboard\StoryboardStep;
use App\Models\AiModel;
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

            // Planning is OpenRouter; the frame image runs on fal.ai (Krea 2 Turbo).
            $expected = $stepKey === AiOperation::KEY_STORYBOARD_FRAME_IMAGE
                ? ImageGenerationProvider::PROVIDER_FAL
                : ImageGenerationProvider::PROVIDER_OPENROUTER;
            $this->assertSame($expected, $config->provider, "step {$stepKey} provider");
        }

        // Planning runs on the strongest Gemini tier with a same-family fallback — the on-demand
        // improve-prompt helper included.
        foreach ([...StoryboardStep::TEXT_STEPS, AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT] as $stepKey) {
            $config = $resolver->for($stepKey);

            $this->assertSame('google/gemini-3.1-pro-preview', $config->model);
            $this->assertSame('google/gemini-3.5-flash', $config->fallbackModel);
        }
    }

    public function test_reseeding_clears_stale_default_and_fallback_model_flags(): void
    {
        // Simulate a pre-upgrade install: the superseded models still carry the flags.
        AiModel::create(['operation_key' => AiOperation::KEY_STORYBOARD_READ_IDEA, 'provider' => AiModel::PROVIDER_OPENROUTER, 'model_id' => 'google/gemini-2.5-flash', 'label' => 'Old default', 'is_default' => true, 'is_active' => true]);
        AiModel::create(['operation_key' => AiOperation::KEY_STORYBOARD_READ_IDEA, 'provider' => AiModel::PROVIDER_OPENROUTER, 'model_id' => 'openai/gpt-4o-mini', 'label' => 'Old fallback', 'is_fallback' => true, 'is_active' => true]);

        $this->seed(StoryboardPipelineSeeder::class);

        $models = AiModel::query()->where('operation_key', AiOperation::KEY_STORYBOARD_READ_IDEA)->get();
        $this->assertSame(['google/gemini-3.1-pro-preview'], $models->where('is_default', true)->pluck('model_id')->all());
        $this->assertSame(['google/gemini-3.5-flash'], $models->where('is_fallback', true)->pluck('model_id')->all());
        // The superseded rows survive as plain catalog entries (still selectable, never auto-picked).
        $this->assertTrue($models->pluck('model_id')->contains('google/gemini-2.5-flash'));
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
        // Each frame plans its own motion phrase (feeds the video clip step's {{motion}}).
        $this->assertArrayHasKey('motion', $scene->inputSchema['properties']['frames']['items']['properties']);

        $image = $resolver->for(AiOperation::KEY_STORYBOARD_FRAME_IMAGE);
        $this->assertNull($image->inputSchema);
        $this->assertSame('high', $image->imageQuality);
        $this->assertSame('fal-ai/krea-2/turbo', $image->model);
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
