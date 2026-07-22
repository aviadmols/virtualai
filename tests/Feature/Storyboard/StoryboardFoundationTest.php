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

    public function test_shot_bounds_derive_from_duration_and_the_pacing_hint(): void
    {
        // 15s at pacing 3 → shots may run up to 6s; between 3 and 15 shots.
        $p = StoryboardProject::factory()->make(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);
        $this->assertSame(6, $p->maxShotSeconds());
        $this->assertSame(3, $p->minShotCount());
        $this->assertSame(15, $p->maxShotCount());

        // A long film hits the hard shots cap; a tiny film can never exceed its seconds.
        $long = StoryboardProject::factory()->make(['duration_seconds' => 600, 'frame_interval_seconds' => 3]);
        $this->assertSame(StoryboardProject::MAX_SHOTS_CAP, $long->maxShotCount());
        $tiny = StoryboardProject::factory()->make(['duration_seconds' => 2, 'frame_interval_seconds' => 3]);
        $this->assertSame(2, $tiny->maxShotCount());
        $this->assertSame(1, $tiny->minShotCount());
    }

    public function test_planned_shot_count_prefers_the_locked_plan_over_the_estimate(): void
    {
        // Before the director runs: the pacing estimate. After: the locked plan's OWN count.
        $p = StoryboardProject::factory()->make(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);
        $this->assertSame(5, $p->plannedShotCount());

        $p->pipeline = [StoryboardProject::PIPE_TIMING => [
            ['frame_number' => 1, 'start_second' => 0, 'end_second' => 7],
            ['frame_number' => 2, 'start_second' => 7, 'end_second' => 15],
        ]];
        $this->assertSame(2, $p->plannedShotCount());
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

    public function test_the_vision_analysis_op_is_seeded_and_referenced_frames_have_an_edit_model(): void
    {
        $this->seed(StoryboardPipelineSeeder::class);
        $resolver = app(AiOperationResolver::class);

        // The reference-analysis (vision) operation resolves on the strongest planning model.
        $analysis = $resolver->for(AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS);
        $this->assertSame('google/gemini-3.1-pro-preview', $analysis->model);
        $this->assertArrayHasKey('description', $analysis->inputSchema['properties']);
        $this->assertArrayHasKey('subject_type', $analysis->inputSchema['properties']);

        // The anchor-less LOOK-SETTING generation upgrades to the premium first-frame model —
        // configured on the operation (param) and catalogued with its provider, never hardcoded.
        $image = $resolver->for(AiOperation::KEY_STORYBOARD_FRAME_IMAGE);
        $this->assertSame('google/gemini-3-pro-image', $image->params['first_frame_model'] ?? null);
        $this->assertDatabaseHas('ai_models', [
            'operation_key' => AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
            'model_id' => 'google/gemini-3-pro-image',
            'provider' => 'openrouter',
            'is_active' => true,
        ]);
        // The chained default is the EDIT-capable model (it SEES the previous frame + refs).
        $this->assertDatabaseHas('ai_models', [
            'operation_key' => AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
            'model_id' => 'fal-ai/nano-banana/edit',
            'provider' => 'fal',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_reseeding_clears_stale_default_and_fallback_model_flags(): void
    {
        // Simulate a pre-upgrade install: the superseded models still carry the flags.
        AiModel::create(['operation_key' => AiOperation::KEY_STORYBOARD_STORY_DIRECTOR, 'provider' => AiModel::PROVIDER_OPENROUTER, 'model_id' => 'google/gemini-2.5-flash', 'label' => 'Old default', 'is_default' => true, 'is_active' => true]);
        AiModel::create(['operation_key' => AiOperation::KEY_STORYBOARD_STORY_DIRECTOR, 'provider' => AiModel::PROVIDER_OPENROUTER, 'model_id' => 'openai/gpt-4o-mini', 'label' => 'Old fallback', 'is_fallback' => true, 'is_active' => true]);

        $this->seed(StoryboardPipelineSeeder::class);

        $models = AiModel::query()->where('operation_key', AiOperation::KEY_STORYBOARD_STORY_DIRECTOR)->get();
        $this->assertSame(['google/gemini-3.1-pro-preview'], $models->where('is_default', true)->pluck('model_id')->all());
        $this->assertSame(['google/gemini-3.5-flash'], $models->where('is_fallback', true)->pluck('model_id')->all());
        // The superseded rows survive as plain catalog entries (still selectable, never auto-picked).
        $this->assertTrue($models->pluck('model_id')->contains('google/gemini-2.5-flash'));
    }

    public function test_text_steps_enforce_a_json_schema_and_the_scene_breakdown_returns_frames(): void
    {
        $this->seed(StoryboardPipelineSeeder::class);
        $resolver = app(AiOperationResolver::class);

        // A text step carries a strict JSON schema; the image step does not. The Story Director's
        // ONE output holds every planning section including the LOCKED shot timing.
        $director = $resolver->for(AiOperation::KEY_STORYBOARD_STORY_DIRECTOR);
        $this->assertIsArray($director->inputSchema);
        $this->assertSame('object', $director->inputSchema['type']);
        $this->assertStringContainsString('{{story_idea}}', $director->userPrompt);
        foreach (['story', 'genre_profile', 'characters', 'visual_bible', 'shot_timing'] as $section) {
            $this->assertArrayHasKey($section, $director->inputSchema['properties']);
        }

        // Shot-based derivation: the DIRECTOR decides the cut list within the project bounds —
        // each shot carries its own duration + ONE concrete camera movement.
        $this->assertStringContainsString('{{min_shots}}', $director->systemPrompt);
        $this->assertStringContainsString('{{max_shot_seconds}}', $director->systemPrompt);
        $shotProps = $director->inputSchema['properties']['shot_timing']['items']['properties'];
        $this->assertArrayHasKey('shot_number', $shotProps);
        $this->assertArrayHasKey('duration_seconds', $shotProps);
        $this->assertArrayHasKey('camera_movement', $shotProps);

        $scene = $resolver->for(AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN);
        $this->assertArrayHasKey('frames', $scene->inputSchema['properties']);
        // Each frame plans its own motion phrase (feeds the video clip step's {{motion}}) and a
        // SCENE-ONLY prompt (the composer appends the locked character/style blocks in code).
        $frameProps = $scene->inputSchema['properties']['frames']['items']['properties'];
        $this->assertArrayHasKey('motion', $frameProps);
        $this->assertArrayHasKey('scene_prompt', $frameProps);
        $this->assertArrayNotHasKey('start_second', $frameProps);
        // The breakdown receives the LOCKED plan as read-only data.
        $this->assertStringContainsString('{{shot_timing}}', $scene->userPrompt);
        $this->assertStringContainsString('{{content_type}}', $scene->userPrompt);

        $image = $resolver->for(AiOperation::KEY_STORYBOARD_FRAME_IMAGE);
        $this->assertNull($image->inputSchema);
        $this->assertSame('high', $image->imageQuality);
        $this->assertSame('fal-ai/nano-banana/edit', $image->model);
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
