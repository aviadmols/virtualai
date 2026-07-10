<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Jobs\CombineStoryboardVideoJob;
use App\Jobs\GenerateStoryboardClipJob;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * Combining into ONE MP4 is admin-only and NEVER charges (clips are billed at generation, not here).
 * ffmpeg is unavailable in the suite, so these pin the ORCHESTRATION: animate submits a clip per
 * frame and reschedules; empty projects fail cleanly; no credit row is ever written.
 */
class StoryboardCombineVideoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 'https://openrouter.ai/api/v1/chat/completions';
    private const FAL_MODEL = 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video';
    private const FAL_SUBMIT = 'https://queue.fal.run/'.self::FAL_MODEL;
    private const FAL_OPENAPI = 'https://fal.ai/api/openapi/queue/openapi.json*';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');
    }

    /** Seed the pipeline + point the clip operation at the AtlasCloud reference-to-video model. */
    private function useAtlasCloud(): void
    {
        config()->set('services.atlascloud.api_key', 'ac-key');
        config()->set('services.atlascloud.base_url', 'https://api.atlascloud.ai/api/v1');
        config()->set('services.atlascloud.timeout', 30);
        $this->seed(StoryboardPipelineSeeder::class);

        AiModel::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)->update(['is_default' => false]);
        AiModel::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->where('provider', AiModel::PROVIDER_ATLASCLOUD)->update(['is_default' => true]);
        AiOperation::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->update(['default_model' => 'bytedance/seedance-2.0/reference-to-video']);
    }

    public function test_animate_with_no_frame_images_fails_cleanly_and_never_charges(): void
    {
        $project = StoryboardProject::factory()->create([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
        ]);

        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_ANIMATE, '1080p', 15))
            ->handle(app(StoryboardVideoComposer::class));

        $project->refresh();
        $this->assertSame(StoryboardProject::VIDEO_FAILED, $project->final_video_status);
        $this->assertNotEmpty($project->final_video_meta['error'] ?? null);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_slideshow_with_no_frame_images_fails_cleanly(): void
    {
        $project = StoryboardProject::factory()->create([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
        ]);

        try {
            (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_SLIDESHOW, '1080p', 15))
                ->handle(app(StoryboardVideoComposer::class));
            $this->fail('Expected the composer to throw when there are no frame images.');
        } catch (Throwable $e) {
            // Expected — no stills to slideshow.
        }

        $project->refresh();
        $this->assertSame(StoryboardProject::VIDEO_FAILED, $project->final_video_status);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_reference_mode_submits_the_story_video_and_reschedules_to_poll(): void
    {
        $this->useAtlasCloud();

        Bus::fake();
        Http::fake([
            'https://api.atlascloud.ai/api/v1/model/generateVideo' => Http::response(['data' => ['id' => 'pred-1']], 200),
            '*' => Http::response('img-bytes', 200), // the reference image the client base64-fetches
        ]);

        $project = StoryboardProject::factory()->create(['story_idea' => 'A knight @image1 fights a dragon']);
        $project->assets()->create(['tag' => 'image1', 'type' => 'character', 'file_path' => 'storyboard/inputs/a.png']);

        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_REFERENCE, '720p', 10, 'A knight fights a dragon', '16:9'))
            ->handle(app(StoryboardVideoComposer::class));

        $project->refresh();
        $this->assertSame(StoryboardProject::VIDEO_GENERATING, $project->final_video_status);
        $this->assertSame('pred-1', $project->final_video_meta['prediction_id'] ?? null);
        $this->assertSame(AiModel::PROVIDER_ATLASCLOUD, $project->final_video_meta['provider'] ?? null);
        // An admin-typed prompt is used verbatim — the director pass never runs.
        $this->assertSame('manual', $project->final_video_meta['prompt_source'] ?? null);
        Http::assertNotSent(fn ($req): bool => $req->url() === self::CHAT);
        Bus::assertDispatched(CombineStoryboardVideoJob::class); // rescheduled to poll the prediction
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_reference_mode_submits_the_directors_prompt_when_the_pass_succeeds(): void
    {
        $this->useAtlasCloud();
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);

        Bus::fake();
        Http::fake([
            self::CHAT => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'video_prompt' => 'DIRECTOR-MARKER [00:00-00:05] wide shot, the knight rises. Cut on action to [00:05-00:10] the dragon lands.',
                ])]]],
                'model' => 'google/gemini-3.1-pro-preview',
                'usage' => ['cost' => 0.01],
            ], 200),
            'https://api.atlascloud.ai/api/v1/model/generateVideo' => Http::response(['data' => ['id' => 'pred-3']], 200),
            '*' => Http::response('img-bytes', 200),
        ]);

        $project = StoryboardProject::factory()->create(['story_idea' => 'A knight fights a dragon']);
        StoryboardFrame::factory()->create(['project_id' => $project->id, 'image_path' => 'storyboard/1/frames/1/a.png']);

        // Empty prompt → the DIRECTOR composes the film prompt from the frames + storyboard.
        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_REFERENCE, '720p', 10, null, '16:9'))
            ->handle(app(StoryboardVideoComposer::class));

        Http::assertSent(fn ($req): bool => str_ends_with($req->url(), '/model/generateVideo')
            && str_contains((string) json_encode($req->data()), 'DIRECTOR-MARKER'));

        $project->refresh();
        $this->assertSame('director', $project->final_video_meta['prompt_source'] ?? null);
        $this->assertStringContainsString('DIRECTOR-MARKER', (string) ($project->final_video_meta['prompt_preview'] ?? ''));
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_reference_mode_survives_a_deleted_director_op_via_the_auto_prompt(): void
    {
        $this->useAtlasCloud();
        AiOperation::where('operation_key', AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR)->delete();

        Bus::fake();
        Http::fake([
            'https://api.atlascloud.ai/api/v1/model/generateVideo' => Http::response(['data' => ['id' => 'pred-4']], 200),
            '*' => Http::response('img-bytes', 200),
        ]);

        $project = StoryboardProject::factory()->create(['story_idea' => 'A knight fights a dragon']);
        StoryboardFrame::factory()->create(['project_id' => $project->id, 'image_path' => 'storyboard/1/frames/1/a.png']);

        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_REFERENCE, '720p', 10, null, '16:9'))
            ->handle(app(StoryboardVideoComposer::class));

        $project->refresh();
        $this->assertSame(StoryboardProject::VIDEO_GENERATING, $project->final_video_status);
        $this->assertSame('auto', $project->final_video_meta['prompt_source'] ?? null);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_reference_mode_clamps_the_duration_to_the_fal_models_own_enum(): void
    {
        config()->set('services.fal.api_key', 'fal-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);
        $this->seed(StoryboardPipelineSeeder::class);

        // Point the clip operation at the seeded fal video model (its ai_models row carries provider=fal).
        AiOperation::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->update(['default_model' => self::FAL_MODEL]);

        Bus::fake();
        Http::fake([
            self::FAL_OPENAPI => Http::response([
                'components' => ['schemas' => ['KlingInput' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'maxLength' => 2500],
                        'image_url' => ['type' => 'string'],
                        'duration' => ['type' => 'string', 'enum' => ['5', '10']],
                    ],
                ]]],
            ], 200),
            self::FAL_SUBMIT => Http::response(['request_id' => 'req-clamp'], 200),
            '*' => Http::response("\x89PNG\r\n\x1a\nREF", 200),
        ]);

        $project = StoryboardProject::factory()->create();
        StoryboardFrame::factory()->create(['project_id' => $project->id, 'image_path' => 'storyboard/1/frames/1/a.png']);

        // 120s requested, but this fal model tops out at "10" — the clamp keeps the submit valid
        // and the meta records both numbers for the builder card.
        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_REFERENCE, '720p', 120, 'A film', '16:9'))
            ->handle(app(StoryboardVideoComposer::class));

        Http::assertSent(fn ($req): bool => $req->url() === self::FAL_SUBMIT
            && $req->data()['duration'] === '10');

        $project->refresh();
        $this->assertSame(120, $project->final_video_meta['requested_seconds'] ?? null);
        $this->assertSame(10, $project->final_video_meta['effective_seconds'] ?? null);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_reference_mode_auto_prompt_carries_the_visual_bible_and_characters(): void
    {
        $this->useAtlasCloud();

        Bus::fake();
        // The '*' catch-all also feeds the director's OpenRouter call garbage — the director pass
        // fails and the deterministic AUTO prompt (under test here) takes over.
        Http::fake([
            'https://api.atlascloud.ai/api/v1/model/generateVideo' => Http::response(['data' => ['id' => 'pred-2']], 200),
            '*' => Http::response('img-bytes', 200),
        ]);

        $project = StoryboardProject::factory()->create([
            'story_idea' => 'A knight defends the STORY-MARKER kingdom',
            'pipeline' => [
                StoryboardProject::PIPE_VISUAL_BIBLE => ['global_style' => 'STYLE-MARKER', 'continuity_rules' => 'RULES-MARKER'],
                StoryboardProject::PIPE_CHARACTERS => ['characters' => [['name' => 'HERO-MARKER', 'description' => 'a tall knight']]],
            ],
        ]);
        $project->assets()->create(['tag' => 'image1', 'type' => 'character', 'file_path' => 'storyboard/inputs/a.png', 'description' => 'ANALYSIS-MARKER: silver-haired knight in navy armor']);
        StoryboardFrame::factory()->create([
            'project_id' => $project->id,
            'description' => 'SCENE-MARKER opening shot',
            'dialogue' => 'DIALOGUE-MARKER welcome home',
        ]);

        // Empty prompt -> the auto prompt must embed the story, visual bible, characters, the
        // reference VISION analyses (character fidelity) and the scenes.
        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_REFERENCE, '720p', 10, null, '16:9'))
            ->handle(app(StoryboardVideoComposer::class));

        Http::assertSent(function ($req): bool {
            if (! str_ends_with($req->url(), '/model/generateVideo')) {
                return false;
            }

            $body = (string) json_encode($req->data());

            return str_contains($body, 'STORY-MARKER')
                && str_contains($body, 'STYLE-MARKER')
                && str_contains($body, 'RULES-MARKER')
                && str_contains($body, 'HERO-MARKER')
                && str_contains($body, 'ANALYSIS-MARKER')
                && str_contains($body, 'SCENE-MARKER')
                && str_contains($body, 'DIALOGUE-MARKER') // the spoken line is voiced at its scene
                && str_contains($body, 'Total duration: 10s')
                && str_contains($body, 'Shot 1 [00:00-00:10]'); // numbered + timed, rescaled to the request
        });
        $this->assertSame('auto', $project->fresh()->final_video_meta['prompt_source'] ?? null);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_reference_mode_with_no_reference_images_fails_cleanly(): void
    {
        $project = StoryboardProject::factory()->create([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
        ]);

        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_REFERENCE, '720p', 10, 'Prompt', '16:9'))
            ->handle(app(StoryboardVideoComposer::class));

        $project->refresh();
        $this->assertSame(StoryboardProject::VIDEO_FAILED, $project->final_video_status);
        $this->assertNotEmpty($project->final_video_meta['error'] ?? null);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_animate_submits_a_clip_per_frame_and_reschedules(): void
    {
        Bus::fake();

        $project = StoryboardProject::factory()->create();
        StoryboardFrame::factory()->create(['project_id' => $project->id, 'image_path' => 'storyboard/1/frames/1/a.png']);
        StoryboardFrame::factory()->create(['project_id' => $project->id, 'image_path' => 'storyboard/1/frames/2/b.png']);

        (new CombineStoryboardVideoJob($project->id, CombineStoryboardVideoJob::MODE_ANIMATE, '1080p', 15))
            ->handle(app(StoryboardVideoComposer::class));

        // A clip is submitted for each frame, and the combiner reschedules itself to poll.
        Bus::assertDispatched(GenerateStoryboardClipJob::class, 2);
        Bus::assertDispatched(CombineStoryboardVideoJob::class);
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $project->frames()->first()->video_status);
        $this->assertDatabaseCount('credit_ledger', 0);
    }
}
