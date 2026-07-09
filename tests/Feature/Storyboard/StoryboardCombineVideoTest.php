<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Jobs\CombineStoryboardVideoJob;
use App\Models\StoryboardProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * Combining all frames into ONE MP4 (ffmpeg) is admin-only and NEVER charges. ffmpeg itself is not
 * available in the suite, so this pins the guardrails around it: a project with no frame images
 * fails cleanly with a surfaced error, and no credit ledger row is ever written.
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

    public function test_combining_with_no_frame_images_fails_cleanly_and_never_charges(): void
    {
        $project = StoryboardProject::factory()->create([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
        ]);

        try {
            (new CombineStoryboardVideoJob($project->id, 15, '1080p'))->handle(app(StoryboardVideoComposer::class));
            $this->fail('Expected the composer to throw when there are no frame images.');
        } catch (Throwable $e) {
            // Expected — no images to stitch.
        }

        $project->refresh();
        $this->assertSame(StoryboardProject::VIDEO_FAILED, $project->final_video_status);
        $this->assertNotEmpty($project->final_video_meta['error'] ?? null);
        $this->assertDatabaseCount('credit_ledger', 0);
    }
}
