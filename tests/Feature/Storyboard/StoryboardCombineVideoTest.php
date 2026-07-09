<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Jobs\CombineStoryboardVideoJob;
use App\Jobs\GenerateStoryboardClipJob;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
