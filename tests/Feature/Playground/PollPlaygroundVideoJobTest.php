<?php

namespace Tests\Feature\Playground;

use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Jobs\PollPlaygroundVideoJob;
use App\Models\PlaygroundRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PollPlaygroundVideoJob — the async Seedance poller. Succeeded => download + store + record the
 * render time (created->updated span) and flat-rate cost; still running => re-dispatch (bounded);
 * terminal failure or the attempt ceiling => a failed run. Never charges.
 */
class PollPlaygroundVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ark.ap-southeast.bytepluses.com/api/v3';
    private const TASK = 'cgt-1';
    private const VIDEO_URL = 'https://cdn.test/out.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BASE);
        config()->set('services.byteplus.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    private function videoRun(array $overrides = []): PlaygroundRun
    {
        return PlaygroundRun::factory()->video()->running()->create(array_merge([
            'provider_task_id' => self::TASK,
            'price_hint_micro_usd' => 400_000,
        ], $overrides));
    }

    private function poll(PlaygroundRun $run): void
    {
        (new PollPlaygroundVideoJob($run->id))->handle(app(VideoProviderRouter::class), app(MediaStorage::class));
    }

    public function test_a_succeeded_task_stores_the_video_and_records_time_cost_and_tokens(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42VIDEO";
        Http::fake([
            self::BASE.'/contents/generations/tasks/'.self::TASK => Http::response([
                'id' => self::TASK,
                'status' => 'succeeded',
                'content' => ['video_url' => self::VIDEO_URL],
                'usage' => ['total_tokens' => 97605],
                'created_at' => 1000,
                'updated_at' => 1042,
            ], 200),
            self::VIDEO_URL => Http::response($mp4, 200),
        ]);

        $run = $this->videoRun();
        $this->poll($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNotNull($run->result_path);
        $this->assertSame('video/mp4', $run->result_mime);
        $this->assertSame(42_000, $run->duration_ms); // (1042 - 1000) s => 42_000 ms
        $this->assertSame(97605, $run->tokens_used);
        $this->assertSame(400_000, $run->cost_micro_usd);
        $this->assertSame(PlaygroundRun::COST_SOURCE_FLAT_RATE, $run->cost_source);
        Storage::disk('s3')->assertExists($run->result_path);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_a_transient_download_failure_reschedules_instead_of_failing(): void
    {
        // The task SUCCEEDED but the CDN download blips — the good result must not be thrown away.
        Bus::fake([PollPlaygroundVideoJob::class]);
        Http::fake([
            self::BASE.'/contents/generations/tasks/'.self::TASK => Http::response([
                'id' => self::TASK,
                'status' => 'succeeded',
                'content' => ['video_url' => self::VIDEO_URL],
            ], 200),
            self::VIDEO_URL => Http::response('', 503),
        ]);

        $run = $this->videoRun();
        $this->poll($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_RUNNING, $run->status);
        $this->assertNull($run->result_path);
        $this->assertSame(1, $run->poll_attempts);
        Bus::assertDispatched(PollPlaygroundVideoJob::class);
    }

    public function test_a_running_task_reschedules_the_poller(): void
    {
        Bus::fake([PollPlaygroundVideoJob::class]);
        Http::fake([self::BASE.'/contents/generations/tasks/'.self::TASK => Http::response(['id' => self::TASK, 'status' => 'running'], 200)]);

        $run = $this->videoRun();
        $this->poll($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_RUNNING, $run->status);
        $this->assertSame(1, $run->poll_attempts);
        Bus::assertDispatched(PollPlaygroundVideoJob::class);
    }

    public function test_a_failed_task_marks_the_run_failed_with_the_message(): void
    {
        Http::fake([self::BASE.'/contents/generations/tasks/'.self::TASK => Http::response([
            'id' => self::TASK,
            'status' => 'failed',
            'error' => ['message' => 'content policy violation'],
        ], 200)]);

        $run = $this->videoRun();
        $this->poll($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('content policy', (string) $run->error);
    }

    public function test_the_attempt_ceiling_ends_as_a_timeout_failure(): void
    {
        Bus::fake([PollPlaygroundVideoJob::class]);
        Http::fake([self::BASE.'/contents/generations/tasks/'.self::TASK => Http::response(['id' => self::TASK, 'status' => 'running'], 200)]);

        // One short of the ceiling: this poll tips it over.
        $run = $this->videoRun(['poll_attempts' => 39]);
        $this->poll($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('timed out', (string) $run->error);
        Bus::assertNotDispatched(PollPlaygroundVideoJob::class);
    }
}
