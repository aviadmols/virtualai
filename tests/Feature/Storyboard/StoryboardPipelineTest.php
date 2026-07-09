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
 * The storyboard pipeline runs the five text steps in order, stores each output, materialises the
 * frames from the scene breakdown, logs every step, and never charges. OpenRouter is faked.
 */
class StoryboardPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const OR_BASE = 'https://openrouter.ai/api/v1';
    private const CHAT = self::OR_BASE.'/chat/completions';

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

    private function fakeHappyPath(int $frameCount): void
    {
        $frames = [];
        for ($i = 1; $i <= $frameCount; $i++) {
            $frames[] = [
                'frame_number' => $i,
                'start_second' => ($i - 1) * 3,
                'end_second' => $i * 3,
                'description' => "Frame {$i} description",
                'motion' => "slow push-in {$i}",
                'image_prompt' => "Cinematic frame {$i}, bright daylight, @location_pool",
                'reference_tags' => ['@location_pool'],
            ];
        }

        Http::fake([self::CHAT => Http::sequence()
            ->push($this->orResponse(['clean_story_summary' => 'A pool party trailer', 'main_intent' => 'entertain', 'creative_direction' => 'comedy trailer']))
            ->push($this->orResponse(['genre' => 'comedy trailer', 'emotional_tone' => 'fun']))
            ->push($this->orResponse(['characters' => [['name' => 'Host', 'description' => 'the party host']]]))
            ->push($this->orResponse(['global_style' => 'realistic cinematic', 'negative_prompt' => 'no cartoon']))
            ->push($this->orResponse(['frames' => $frames]))]);
    }

    public function test_the_pipeline_runs_every_step_materialises_frames_and_logs_them(): void
    {
        $project = StoryboardProject::factory()->create(['duration_seconds' => 15, 'frame_interval_seconds' => 3]);
        StoryboardAsset::factory()->create(['project_id' => $project->id, 'tag' => 'location_pool']);

        $this->fakeHappyPath(5);

        app(StoryboardPipeline::class)->run($project);

        $project->refresh();
        $this->assertSame(StoryboardProject::STATUS_READY, $project->status);

        // Each single-object step landed under pipeline[...].
        $this->assertArrayHasKey(StoryboardProject::PIPE_STORY, $project->pipeline);
        $this->assertArrayHasKey(StoryboardProject::PIPE_GENRE, $project->pipeline);
        $this->assertArrayHasKey(StoryboardProject::PIPE_CHARACTERS, $project->pipeline);
        $this->assertArrayHasKey(StoryboardProject::PIPE_VISUAL_BIBLE, $project->pipeline);

        // The scene breakdown became frames.
        $this->assertSame(5, $project->frames()->count());
        $first = $project->frames()->first();
        $this->assertSame(1, $first->frame_number);
        $this->assertStringContainsString('Cinematic frame 1', (string) $first->image_prompt);
        $this->assertSame('slow push-in 1', (string) $first->motion_prompt);
        $this->assertSame(StoryboardFrame::STATUS_PENDING, $first->status);

        // Every step is logged succeeded with a model + duration.
        $this->assertSame(5, $project->stepRuns()->count());
        $this->assertSame(5, $project->stepRuns()->where('status', StoryboardStepRun::STATUS_SUCCEEDED)->count());
        $this->assertNotNull($project->stepRuns()->first()->duration_ms);

        // A pipeline run NEVER charges.
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_a_failed_step_fails_the_project_and_materialises_no_frames(): void
    {
        $project = StoryboardProject::factory()->create();

        // Every call errors -> the first step (read_idea) fails.
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
}
