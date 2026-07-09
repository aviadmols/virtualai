<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardClipGenerator;
use App\Jobs\GenerateStoryboardClipJob;
use App\Jobs\PollStoryboardClipJob;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Per-frame video clips: the generator submits an image-to-video (Seedance) task for a frame's
 * image and the poller downloads + stores the mp4. Async submit→poll, bounded, never charges.
 * BytePlus is faked. Uses a local media disk so the first-frame signed url resolves in tests.
 */
class StoryboardClipTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ark.ap-southeast.bytepluses.com/api/v3';
    private const TASKS = self::BASE.'/contents/generations/tasks';
    private const TASK = 'cgt-sb1';
    private const VIDEO_URL = 'https://cdn.test/clip.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BASE);
        config()->set('services.byteplus.timeout', 30);
        config()->set('trayon.media.disk', 'public'); // local driver -> signedUrl via the signed route
        Storage::fake('public');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    private function frame(array $overrides = []): StoryboardFrame
    {
        $project = StoryboardProject::factory()->create();

        return StoryboardFrame::factory()->create(array_merge([
            'project_id' => $project->id,
            'image_path' => 'storyboard/1/frames/1/img.png',
            'image_prompt' => 'Cinematic pool party',
        ], $overrides));
    }

    public function test_generate_clip_job_submits_and_dispatches_the_poller(): void
    {
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([self::TASKS => Http::response(['id' => self::TASK], 200)]);

        $frame = $this->frame();
        (new GenerateStoryboardClipJob($frame->id))->handle(app(StoryboardClipGenerator::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertSame(self::TASK, $frame->video_task_id);
        Bus::assertDispatched(PollStoryboardClipJob::class);

        // The submit body carries the frame image as the first_frame.
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/contents/generations/tasks')
            && $req->data()['content'][1]['role'] === 'first_frame');
    }

    public function test_a_succeeded_poll_stores_the_clip_and_render_time(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42CLIP";
        Http::fake([
            self::TASKS.'/'.self::TASK => Http::response([
                'id' => self::TASK,
                'status' => 'succeeded',
                'content' => ['video_url' => self::VIDEO_URL],
                'created_at' => 1000,
                'updated_at' => 1042,
            ], 200),
            self::VIDEO_URL => Http::response($mp4, 200),
        ]);

        $frame = $this->frame(['video_status' => StoryboardFrame::VIDEO_GENERATING, 'video_task_id' => self::TASK]);
        (new PollStoryboardClipJob($frame->id))->handle(app(\App\Domain\Ai\BytePlusVideoClient::class), app(\App\Domain\Media\MediaStorage::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_READY, $frame->video_status);
        $this->assertStringEndsWith('.mp4', (string) $frame->video_path);
        $this->assertSame(42_000, $frame->video_duration_ms);
        Storage::disk('public')->assertExists($frame->video_path);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_a_running_poll_reschedules(): void
    {
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([self::TASKS.'/'.self::TASK => Http::response(['id' => self::TASK, 'status' => 'running'], 200)]);

        $frame = $this->frame(['video_status' => StoryboardFrame::VIDEO_GENERATING, 'video_task_id' => self::TASK]);
        (new PollStoryboardClipJob($frame->id))->handle(app(\App\Domain\Ai\BytePlusVideoClient::class), app(\App\Domain\Media\MediaStorage::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertSame(1, $frame->video_poll_attempts);
        Bus::assertDispatched(PollStoryboardClipJob::class);
    }
}
