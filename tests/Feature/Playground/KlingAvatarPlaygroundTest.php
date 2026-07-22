<?php

namespace Tests\Feature\Playground;

use App\Jobs\PollPlaygroundVideoJob;
use App\Jobs\RunPlaygroundJob;
use App\Models\PlaygroundRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The talking-avatar Playground pipeline (native Kling AI Avatar): RunPlaygroundJob submits an
 * image + audio to /v1/videos/avatar/image2video, PollPlaygroundVideoJob polls the task and stores
 * the resulting mp4. Kling HTTP + the media disk are faked — no real calls, no charge (a Playground
 * run never touches the ledger).
 */
class KlingAvatarPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    private const KLING_BASE = 'https://api-singapore.klingai.com';

    private const AVATAR_PATH = '/v1/videos/avatar/image2video';

    private const VIDEO_URL = 'https://cdn.example.com/avatar-result.mp4';

    private const MP4_BYTES = "\x00\x00\x00\x18ftypmp42AVATAR-VIDEO";

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.kling.base_url', self::KLING_BASE);
        config()->set('services.kling.api_key', 'api-key-kling-test');
        config()->set('services.kling.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    private function fakeKling(string $taskStatus = 'succeed'): void
    {
        Http::fake([
            // Query (poll) — the id suffix; must precede the bare submit pattern.
            self::KLING_BASE.self::AVATAR_PATH.'/*' => Http::response([
                'code' => 0,
                'data' => [
                    'task_status' => $taskStatus,
                    'task_result' => ['videos' => [['url' => self::VIDEO_URL]]],
                ],
            ], 200),
            // Create (submit).
            self::KLING_BASE.self::AVATAR_PATH => Http::response([
                'code' => 0,
                'data' => ['task_id' => 'avatar-task-1', 'task_status' => 'submitted'],
            ], 200),
            // The result mp4 download.
            self::VIDEO_URL => Http::response(self::MP4_BYTES, 200),
        ]);
    }

    private function avatarRun(): PlaygroundRun
    {
        // Stored image + audio on the (faked) media disk; signedUrl turns them into fetchable urls.
        Storage::disk('s3')->put('playground/inputs/face.png', 'PNG');
        Storage::disk('s3')->put('playground/inputs/voice.mp3', 'MP3');

        return PlaygroundRun::create([
            'kind' => PlaygroundRun::KIND_AVATAR,
            'provider' => PlaygroundRun::PROVIDER_KLING,
            'model_id' => '',
            'prompt' => 'excited, nodding while speaking',
            'input_paths' => ['playground/inputs/face.png'],
            'audio_path' => 'playground/inputs/voice.mp3',
            'status' => PlaygroundRun::STATUS_QUEUED,
            'meta' => [PlaygroundRun::META_MODE => 'pro'],
        ]);
    }

    private function runJob(object $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_an_avatar_run_submits_image_plus_audio_and_stores_the_video(): void
    {
        $this->fakeKling();
        Bus::fake([PollPlaygroundVideoJob::class]); // RunPlaygroundJob dispatches it; we drive it by hand
        $run = $this->avatarRun();

        // 1) Submit: image + audio + mode go to the native avatar endpoint; the task id is stored.
        $this->runJob(new RunPlaygroundJob($run->id));

        $run->refresh();
        $this->assertSame('avatar-task-1', $run->provider_task_id);
        $this->assertSame(PlaygroundRun::STATUS_RUNNING, $run->status);
        Bus::assertDispatched(PollPlaygroundVideoJob::class);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), self::AVATAR_PATH)) {
                return false;
            }
            $data = $request->data();

            return is_string($data['image'] ?? null)
                && is_string($data['sound_file'] ?? null)
                && ($data['mode'] ?? null) === 'pro';
        });

        // 2) Poll → succeed: the mp4 is downloaded + stored, the run is done.
        $this->runJob(new PollPlaygroundVideoJob($run->id));

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame('video/mp4', $run->result_mime);
        $this->assertNotNull($run->result_path);
        Storage::disk('s3')->assertExists($run->result_path);
        $this->assertTrue($run->producesVideo());
    }

    public function test_a_failed_avatar_task_lands_the_run_failed_and_never_charges(): void
    {
        $this->fakeKling(taskStatus: 'failed');
        Bus::fake([PollPlaygroundVideoJob::class]);
        $run = $this->avatarRun();

        $this->runJob(new RunPlaygroundJob($run->id));
        $this->runJob(new PollPlaygroundVideoJob($run->id));

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->result_path);
    }
}
