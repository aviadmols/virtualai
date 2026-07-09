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

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');
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
        config()->set('services.atlascloud.api_key', 'ac-key');
        config()->set('services.atlascloud.base_url', 'https://api.atlascloud.ai/api/v1');
        config()->set('services.atlascloud.timeout', 30);
        $this->seed(StoryboardPipelineSeeder::class);

        // Point the clip operation at the AtlasCloud reference-to-video model.
        AiModel::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)->update(['is_default' => false]);
        AiModel::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->where('provider', AiModel::PROVIDER_ATLASCLOUD)->update(['is_default' => true]);
        AiOperation::where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->update(['default_model' => 'bytedance/seedance-2.0/reference-to-video']);

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
        Bus::assertDispatched(CombineStoryboardVideoJob::class); // rescheduled to poll the prediction
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
