<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Domain\Storyboard\StoryboardClipGenerator;
use App\Jobs\GenerateStoryboardClipJob;
use App\Jobs\PollStoryboardClipJob;
use App\Models\AiOperation;
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

    // AtlasCloud (the alternate async video provider) endpoints + ids.
    private const AC_BASE = 'https://api.atlascloud.ai/api/v1';
    private const AC_SUBMIT = self::AC_BASE.'/model/generateVideo';
    private const AC_TASK = 'pred-sb1';
    private const AC_PREDICTION = self::AC_BASE.'/model/prediction/'.self::AC_TASK;
    private const AC_MODEL = 'bytedance/seedance-2.0/reference-to-video';

    // fal.ai (queue API) endpoints + the seeded non-default clip model. The task id is COMPOSITE
    // ("{model}|{request_id}") — fal's status/result routes need the model path.
    private const FAL_MODEL = 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video';
    private const FAL_SUBMIT = 'https://queue.fal.run/'.self::FAL_MODEL;
    private const FAL_REQUEST = 'req-fal1';
    private const FAL_TASK = self::FAL_MODEL.'|'.self::FAL_REQUEST;
    private const FAL_STATUS = self::FAL_SUBMIT.'/requests/'.self::FAL_REQUEST.'/status';
    private const FAL_RESULT = self::FAL_SUBMIT.'/requests/'.self::FAL_REQUEST.'/response';

    // Kling native (the seeded clip default): i2v endpoint + a composite "{path}|{task}" id.
    private const KLING_BASE = 'https://api-singapore.klingai.com';
    private const KLING_I2V = self::KLING_BASE.'/v1/videos/image2video';
    private const KLING_TASK = 'kv-task-1';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BASE);
        config()->set('services.byteplus.timeout', 30);
        config()->set('services.kling.api_key', 'api-key-kling-test');
        config()->set('services.kling.base_url', self::KLING_BASE);
        config()->set('services.kling.timeout', 30);
        config()->set('trayon.media.disk', 'public'); // local driver -> signedUrl via the signed route
        Storage::fake('public');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    /** One Kling video envelope. */
    private function klingTask(string $status): array
    {
        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => 'req-1',
            'data' => ['task_id' => self::KLING_TASK, 'task_status' => $status],
        ];
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

    public function test_generate_clip_job_submits_on_kling_and_dispatches_the_poller(): void
    {
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([
            self::KLING_I2V => Http::response($this->klingTask('submitted'), 200),
            // The signed frame-image fetches — the client INLINES frames as raw base64.
            '*' => Http::response("\x89PNG\r\n\x1a\nIMG", 200),
        ]);

        $frame = $this->frame([
            'motion_prompt' => 'slow pan left across the pool',
            'dialogue' => 'Welcome to the party!',
            'camera_angle' => 'low-angle wide, 24mm',
            'start_second' => 0,
            'end_second' => 7,
        ]);

        // The NEXT shot's opening frame exists → it rides as this clip's END frame.
        StoryboardFrame::factory()->create([
            'project_id' => $frame->project_id,
            'frame_number' => (int) $frame->frame_number + 1,
            'image_path' => 'storyboard/2/frames/2/img.png',
        ]);
        Storage::disk('public')->put('storyboard/2/frames/2/img.png', "\x89PNG\r\n\x1a\nNEXT");

        (new GenerateStoryboardClipJob($frame->id))->handle(app(StoryboardClipGenerator::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertSame('/v1/videos/image2video|'.self::KLING_TASK, $frame->video_task_id);
        // The tail landed → the composer must keep this clip at full length (the cut IS its end frame).
        $this->assertTrue($frame->video_meta[StoryboardFrame::META_TAIL_APPLIED] ?? false);
        Bus::assertDispatched(PollStoryboardClipJob::class);

        Http::assertSent(function ($req): bool {
            if (! str_ends_with($req->url(), '/v1/videos/image2video')) {
                return false;
            }
            $body = (array) json_decode((string) $req->body(), true);
            $prompt = (string) ($body['prompt'] ?? '');

            return ($body['model_name'] ?? null) === 'kling-v2-5-turbo'
                // Start frame RAW base64 (no data: prefix) + the SHOT-CONNECTION end frame.
                && ($body['image'] ?? '') !== '' && ! str_contains((string) $body['image'], 'data:')
                && ($body['image_tail'] ?? '') !== '' && ! str_contains((string) $body['image_tail'], 'data:')
                // 1080p → Kling `pro` mode (image_tail needs it); 7s shot → Kling enum '5'.
                && ($body['mode'] ?? null) === 'pro'
                && ($body['duration'] ?? null) === '5'
                // Motion + camera work + the spoken line all reach the prompt.
                && str_contains($prompt, 'slow pan left across the pool')
                && str_contains($prompt, 'low-angle wide, 24mm')
                && str_contains($prompt, 'Welcome to the party!');
        });
    }

    public function test_the_last_frame_submits_without_a_tail_and_durations_clamp_to_the_kling_enum(): void
    {
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([
            self::KLING_I2V => Http::response($this->klingTask('submitted'), 200),
            '*' => Http::response("\x89PNG\r\n\x1a\nIMG", 200),
        ]);

        // No next frame → no image_tail. A 20s shot clamps 12 (op bound) → '10' (Kling enum);
        // a 1s shot lifts to 3 (op bound) → '5' (Kling's shortest).
        $long = $this->frame(['start_second' => 0, 'end_second' => 20]);
        (new GenerateStoryboardClipJob($long->id))->handle(app(StoryboardClipGenerator::class));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1/videos/image2video')
            && ! array_key_exists('image_tail', (array) json_decode((string) $req->body(), true))
            && (json_decode((string) $req->body(), true)['duration'] ?? null) === '10');
        // No tail → not a landing clip → the composer trims it to its shot length.
        $this->assertFalse($long->fresh()->video_meta[StoryboardFrame::META_TAIL_APPLIED]);

        $short = $this->frame(['start_second' => 0, 'end_second' => 1]);
        (new GenerateStoryboardClipJob($short->id))->handle(app(StoryboardClipGenerator::class));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1/videos/image2video')
            && (json_decode((string) $req->body(), true)['duration'] ?? null) === '5');
    }

    public function test_a_rejected_tail_retries_once_without_it(): void
    {
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([
            self::KLING_I2V => Http::sequence()
                // Kling 400s the tail (model/mode gate) → the submit retries WITHOUT it.
                ->push(['code' => 1201, 'message' => 'image_tail not supported'], 400)
                ->push($this->klingTask('submitted'), 200),
            '*' => Http::response("\x89PNG\r\n\x1a\nIMG", 200),
        ]);

        $frame = $this->frame(['start_second' => 0, 'end_second' => 5]);
        StoryboardFrame::factory()->create([
            'project_id' => $frame->project_id,
            'frame_number' => (int) $frame->frame_number + 1,
            'image_path' => 'storyboard/2/frames/2/img.png',
        ]);
        Storage::disk('public')->put('storyboard/2/frames/2/img.png', "\x89PNG\r\n\x1a\nNEXT");

        (new GenerateStoryboardClipJob($frame->id))->handle(app(StoryboardClipGenerator::class));

        $frame->refresh();
        // The clip still renders — the drop is recorded, never silent.
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertTrue((bool) ($frame->video_meta['tail_dropped'] ?? false));
        $this->assertNotSame('', (string) ($frame->video_meta['tail_error'] ?? ''));
        // A dropped tail does NOT land on the next shot → the clip is trimmed like any other.
        $this->assertFalse($frame->video_meta[StoryboardFrame::META_TAIL_APPLIED]);

        // First submit carried the tail; the retry did not.
        $tails = [];
        Http::assertSent(function ($req) use (&$tails): bool {
            if (str_ends_with($req->url(), '/v1/videos/image2video')) {
                $tails[] = array_key_exists('image_tail', (array) json_decode((string) $req->body(), true));
            }

            return true;
        });
        $this->assertSame([true, false], $tails);
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
        (new PollStoryboardClipJob($frame->id))->handle(app(\App\Domain\Ai\VideoProviderRouter::class), app(\App\Domain\Media\MediaStorage::class));

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
        (new PollStoryboardClipJob($frame->id))->handle(app(\App\Domain\Ai\VideoProviderRouter::class), app(\App\Domain\Media\MediaStorage::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertSame(1, $frame->video_poll_attempts);
        Bus::assertDispatched(PollStoryboardClipJob::class);
    }

    /** Point the clip operation at the (seeded) AtlasCloud model + configure the provider. */
    private function useAtlasCloud(): void
    {
        config()->set('services.atlascloud.api_key', 'ac-real-key');
        config()->set('services.atlascloud.base_url', self::AC_BASE);
        config()->set('services.atlascloud.timeout', 30);

        AiOperation::query()
            ->where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->update(['default_model' => self::AC_MODEL]);
    }

    public function test_the_generator_routes_to_atlascloud_when_the_clip_op_provider_is_atlascloud(): void
    {
        $this->useAtlasCloud();
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([
            self::AC_SUBMIT => Http::response(['data' => ['id' => self::AC_TASK]], 200),
            '*' => Http::response("\x89PNG\r\n\x1a\nREF", 200), // the frame-image download (signed route)
        ]);

        $frame = $this->frame();
        (new GenerateStoryboardClipJob($frame->id))->handle(app(StoryboardClipGenerator::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertSame(self::AC_TASK, $frame->video_task_id);
        $this->assertSame('atlascloud', $frame->video_meta['provider']);
        Bus::assertDispatched(PollStoryboardClipJob::class);

        // AtlasCloud must receive the frame image as a base64 data URI (the private disk isn't reachable).
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/model/generateVideo')
            && str_starts_with($req->data()['reference_images'][0], 'data:'));

        $this->assertDatabaseCount('credit_ledger', 0);
    }

    /** Point the clip operation at the (seeded) fal model + configure the provider. */
    private function useFal(): void
    {
        config()->set('services.fal.api_key', 'fal-real-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);

        AiOperation::query()
            ->where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->update(['default_model' => self::FAL_MODEL]);
    }

    public function test_the_generator_routes_to_fal_when_the_clip_op_provider_is_fal(): void
    {
        $this->useFal();
        Bus::fake([PollStoryboardClipJob::class]);
        Http::fake([
            self::FAL_SUBMIT => Http::response(['request_id' => self::FAL_REQUEST], 200),
            '*' => Http::response("\x89PNG\r\n\x1a\nREF", 200), // the frame-image download (signed route)
        ]);

        $frame = $this->frame();
        (new GenerateStoryboardClipJob($frame->id))->handle(app(StoryboardClipGenerator::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_GENERATING, $frame->video_status);
        $this->assertSame(self::FAL_TASK, $frame->video_task_id);
        $this->assertSame('fal', $frame->video_meta['provider']);
        Bus::assertDispatched(PollStoryboardClipJob::class);

        // fal must receive the frame image as a base64 data URI (the private disk isn't reachable).
        Http::assertSent(fn ($req) => $req->url() === self::FAL_SUBMIT
            && str_starts_with((string) ($req->data()['image_url'] ?? ''), 'data:'));

        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_the_poller_uses_the_fal_client_and_stores_the_clip(): void
    {
        $this->useFal();
        $mp4 = "\x00\x00\x00\x18ftypmp42CLIP";
        Http::fake([
            self::FAL_STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::FAL_RESULT => Http::response(['video' => ['url' => self::VIDEO_URL]], 200),
            self::VIDEO_URL => Http::response($mp4, 200),
        ]);

        $frame = $this->frame([
            'video_status' => StoryboardFrame::VIDEO_GENERATING,
            'video_task_id' => self::FAL_TASK,
            'video_meta' => ['provider' => 'fal', 'cost' => 200_000],
        ]);
        (new PollStoryboardClipJob($frame->id))->handle(app(VideoProviderRouter::class), app(MediaStorage::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_READY, $frame->video_status);
        $this->assertStringEndsWith('.mp4', (string) $frame->video_path);
        Storage::disk('public')->assertExists($frame->video_path);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_the_poller_uses_the_atlascloud_client_and_stores_the_clip(): void
    {
        $this->useAtlasCloud();
        $mp4 = "\x00\x00\x00\x18ftypmp42CLIP";
        Http::fake([
            self::AC_PREDICTION => Http::response([
                'data' => ['status' => 'completed', 'outputs' => [self::VIDEO_URL]],
            ], 200),
            self::VIDEO_URL => Http::response($mp4, 200),
        ]);

        $frame = $this->frame([
            'video_status' => StoryboardFrame::VIDEO_GENERATING,
            'video_task_id' => self::AC_TASK,
            'video_meta' => ['provider' => 'atlascloud', 'cost' => 200_000],
        ]);
        (new PollStoryboardClipJob($frame->id))->handle(app(VideoProviderRouter::class), app(MediaStorage::class));

        $frame->refresh();
        $this->assertSame(StoryboardFrame::VIDEO_READY, $frame->video_status);
        $this->assertStringEndsWith('.mp4', (string) $frame->video_path);
        $this->assertSame(200_000, $frame->video_cost_micro_usd);
        Storage::disk('public')->assertExists($frame->video_path);
        $this->assertDatabaseCount('credit_ledger', 0);
    }
}
