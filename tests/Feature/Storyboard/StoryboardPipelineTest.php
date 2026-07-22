<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardPipeline;
use App\Jobs\RunStoryboardPipelineJob;
use App\Models\StoryboardAsset;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The storyboard pipeline runs TWO planning calls (Story Director → Scene Breakdown), splits the
 * director's sections into the pipeline bags, stamps the frames with the LOCKED shot timing (never
 * the breakdown's own numbers), composes each frame's image_prompt deterministically from the
 * locked bibles, logs every step, and never charges. OpenRouter is faked.
 */
class StoryboardPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const OR_BASE = 'https://openrouter.ai/api/v1';

    private const CHAT = self::OR_BASE.'/chat/completions';

    // The Story Director's VARIED cut list (2+2+2+4+5 = 15s) in the shot-based shape — the
    // director DECIDES the count + camera movement; the normalizer locks it.
    private const DIRECTOR_SHOTS = [
        ['shot_number' => 1, 'duration_seconds' => 2, 'camera_movement' => 'static wide establishing'],
        ['shot_number' => 2, 'duration_seconds' => 2, 'camera_movement' => 'slow push-in'],
        ['shot_number' => 3, 'duration_seconds' => 2, 'camera_movement' => 'handheld follow'],
        ['shot_number' => 4, 'duration_seconds' => 4, 'camera_movement' => 'low-angle tracking'],
        ['shot_number' => 5, 'duration_seconds' => 5, 'camera_movement' => 'static close-up'],
    ];

    // The contiguous locked plan those shots normalize into.
    private const LOCKED_TIMING = [
        ['frame_number' => 1, 'start_second' => 0, 'end_second' => 2, 'camera_movement' => 'static wide establishing'],
        ['frame_number' => 2, 'start_second' => 2, 'end_second' => 4, 'camera_movement' => 'slow push-in'],
        ['frame_number' => 3, 'start_second' => 4, 'end_second' => 6, 'camera_movement' => 'handheld follow'],
        ['frame_number' => 4, 'start_second' => 6, 'end_second' => 10, 'camera_movement' => 'low-angle tracking'],
        ['frame_number' => 5, 'start_second' => 10, 'end_second' => 15, 'camera_movement' => 'static close-up'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::OR_BASE);
        config()->set('services.openrouter.timeout', 30);
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    /** @param array<string,mixed> $json */
    private function orResponse(array $json): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($json)]]],
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.001],
        ];
    }

    /** @return array<string,mixed> the Story Director's single locked-plan output */
    private function directorOutput(int $frameCount): array
    {
        return [
            'story' => [
                'clean_story_summary' => 'A pool party rescue',
                'main_intent' => 'entertain',
                'creative_direction' => 'family adventure',
                'content_type' => StoryboardProject::CONTENT_COMPLETE,
            ],
            'genre_profile' => ['genre' => 'Family Adventure', 'emotional_tone' => 'warm', 'negative_rules' => ['no shaky-cam blur']],
            'characters' => ['characters' => [[
                'name' => 'Matan',
                'tag' => 'image1',
                'description' => 'the older brother',
                'identity_lock' => 'short dark hair, athletic build.',
                'story_wardrobe' => 'purple and yellow jersey number 6, black shorts, white sneakers',
                'signature_prop' => 'a red whistle',
            ]]],
            'visual_bible' => [
                'global_style' => 'realistic cinematic film still.',
                'lighting' => 'warm golden hour',
                'color_palette' => 'turquoise water, golden light',
                'negative_prompt' => 'blurry, watermark',
            ],
            'shot_timing' => array_slice(self::DIRECTOR_SHOTS, 0, $frameCount),
        ];
    }

    /** @return array<int,array<string,mixed>> scene-only frames CLAIMING wrong uniform timing */
    private function breakdownFrames(int $frameCount): array
    {
        $frames = [];
        for ($i = 1; $i <= $frameCount; $i++) {
            $frames[] = [
                'frame_number' => $i,
                'description' => "Frame {$i} description",
                'motion' => "slow push-in {$i}",
                'scene_prompt' => "Scene beat {$i}: Matan runs toward the pool at @location_pool",
                'characters' => ['Matan'],
                'reference_tags' => ['@location_pool'],
                'negative_prompt' => 'cartoon, blurry',
            ];
        }

        return $frames;
    }

    private function fakeHappyPath(int $frameCount): void
    {
        Http::fake([self::CHAT => Http::sequence()
            ->push($this->orResponse($this->directorOutput($frameCount)))
            ->push($this->orResponse(['frames' => $this->breakdownFrames($frameCount)]))]);
    }

    public function test_the_pipeline_runs_two_steps_materialises_frames_and_logs_them(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);
        StoryboardAsset::factory()->create([
            'project_id' => $project->id,
            'tag' => 'location_pool',
            'description' => 'GROUND-TRUTH: a turquoise infinity pool at golden hour',
        ]);

        $this->fakeHappyPath(5);

        app(StoryboardPipeline::class)->run($project);

        $project->refresh();
        $this->assertSame(StoryboardProject::STATUS_READY, $project->status);

        // The director's single output was split into the known bags + the locked timing.
        $this->assertArrayHasKey(StoryboardProject::PIPE_STORY, $project->pipeline);
        $this->assertArrayHasKey(StoryboardProject::PIPE_GENRE, $project->pipeline);
        $this->assertArrayHasKey(StoryboardProject::PIPE_CHARACTERS, $project->pipeline);
        $this->assertArrayHasKey(StoryboardProject::PIPE_VISUAL_BIBLE, $project->pipeline);
        $this->assertSame(self::LOCKED_TIMING, $project->pipeline[StoryboardProject::PIPE_TIMING]);

        // The scene breakdown became frames.
        $this->assertSame(5, $project->frames()->count());
        $first = $project->frames()->first();
        $this->assertSame(1, $first->frame_number);
        $this->assertSame('slow push-in 1', (string) $first->motion_prompt);
        $this->assertSame(StoryboardFrame::STATUS_PENDING, $first->status);

        // TWO planning calls, both logged succeeded with a model + duration.
        $this->assertSame(2, $project->stepRuns()->count());
        $this->assertSame(2, $project->stepRuns()->where('status', StoryboardStepRun::STATUS_SUCCEEDED)->count());
        $this->assertNotNull($project->stepRuns()->first()->duration_ms);

        // The VISION ground truth of every @tag reaches the planning prompts.
        $this->assertStringContainsString(
            'GROUND-TRUTH: a turquoise infinity pool',
            (string) ($project->stepRuns()->first()->input['reference_descriptions'] ?? ''),
        );

        // A pipeline run NEVER charges.
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_frame_timing_comes_from_the_locked_plan_not_the_breakdown(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);
        $this->fakeHappyPath(5);

        app(StoryboardPipeline::class)->run($project);

        // The breakdown carried NO timing at all — every frame is stamped from the LOCKED
        // varied plan (2,2,2,4,5), not uniform 3s slices.
        $timings = $project->frames()->get()->map(
            static fn (StoryboardFrame $f): array => [$f->start_second, $f->end_second],
        )->all();

        $this->assertSame([[0, 2], [2, 4], [4, 6], [6, 10], [10, 15]], $timings);
    }

    public function test_image_prompts_are_composed_deterministically_from_the_locked_bibles(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);
        $this->fakeHappyPath(5);

        app(StoryboardPipeline::class)->run($project);

        $frames = $project->frames()->get();

        // Every frame: its own scene beat + the IDENTICAL locked character + style blocks.
        $characterBlock = null;
        foreach ($frames as $frame) {
            $prompt = (string) $frame->image_prompt;
            $this->assertStringContainsString("Scene beat {$frame->frame_number}", $prompt);
            // Identity is anchored to the reference tag — not a re-invented description.
            $this->assertStringContainsString('exact person in @image1', $prompt);
            $this->assertStringContainsString('purple and yellow jersey number 6', $prompt);
            $this->assertStringContainsString('realistic cinematic film still', $prompt);

            // The character+style tail (everything after the scene beat) is byte-identical.
            $tail = (string) strstr($prompt, 'Matan is the exact person');
            $characterBlock ??= $tail;
            $this->assertSame($characterBlock, $tail);
        }

        // The frame's negative merges its own terms with the visual bible's (deduped).
        $this->assertSame('cartoon, blurry, watermark', (string) $frames->first()->negative_prompt);
    }

    public function test_unusable_proposed_timing_falls_back_to_uniform_slices(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);

        $director = $this->directorOutput(5);
        $director['shot_timing'] = [['frame_number' => 1, 'start_second' => 0, 'end_second' => 15]]; // wrong count

        Http::fake([self::CHAT => Http::sequence()
            ->push($this->orResponse($director))
            ->push($this->orResponse(['frames' => $this->breakdownFrames(5)]))]);

        app(StoryboardPipeline::class)->run($project);

        $timings = $project->frames()->get()->map(
            static fn (StoryboardFrame $f): array => [$f->start_second, $f->end_second],
        )->all();

        $this->assertSame([[0, 3], [3, 6], [6, 9], [9, 12], [12, 15]], $timings);
    }

    public function test_a_failed_step_fails_the_project_and_materialises_no_frames(): void
    {
        $project = StoryboardProject::factory()->create();

        // Every call errors -> the first step (story director) fails.
        Http::fake([self::CHAT => Http::response(['error' => ['message' => 'bad request']], 400)]);

        app(StoryboardPipeline::class)->run($project);

        $project->refresh();
        $this->assertSame(StoryboardProject::STATUS_FAILED, $project->status);
        $this->assertSame(0, $project->frames()->count());
        $this->assertSame(StoryboardStepRun::STATUS_FAILED, $project->stepRuns()->first()->status);
    }

    public function test_the_job_runs_the_pipeline(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 9, 'frame_interval_seconds' => 3]);
        $this->fakeHappyPath(3);

        (new RunStoryboardPipelineJob($project->id))->handle(app(StoryboardPipeline::class));

        $this->assertSame(StoryboardProject::STATUS_READY, $project->refresh()->status);
        $this->assertSame(3, $project->frames()->count());
    }

    public function test_a_frame_without_a_motion_beat_falls_back_to_the_slots_camera_movement(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);

        $frames = $this->breakdownFrames(5);
        unset($frames[2]['motion']); // frame 3 arrives with NO motion beat

        Http::fake([self::CHAT => Http::sequence()
            ->push($this->orResponse($this->directorOutput(5)))
            ->push($this->orResponse(['frames' => $frames]))]);

        app(StoryboardPipeline::class)->run($project);

        // The clip step always has something concrete to animate: the LOCKED camera move.
        $third = $project->frames()->where('frame_number', 3)->first();
        $this->assertSame('handheld follow', (string) $third->motion_prompt);
    }

    public function test_a_breakdown_count_mismatch_is_reconciled_to_the_locked_plan(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);

        // The director locked 5 shots; the breakdown returned only 3 frames.
        Http::fake([self::CHAT => Http::sequence()
            ->push($this->orResponse($this->directorOutput(5)))
            ->push($this->orResponse(['frames' => $this->breakdownFrames(3)]))]);

        app(StoryboardPipeline::class)->run($project);

        // 3 frames materialise; the LAST stretches to the plan's end so the film still covers 15s.
        $timings = $project->frames()->get()->map(
            static fn (StoryboardFrame $f): array => [$f->start_second, $f->end_second],
        )->all();

        $this->assertSame([[0, 2], [2, 4], [4, 15]], $timings);
    }
}
