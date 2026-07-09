<?php

namespace Tests\Feature\Playground;

use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Domain\Playground\PlaygroundImageRunner;
use App\Jobs\PollPlaygroundVideoJob;
use App\Jobs\RunPlaygroundJob;
use App\Models\PlaygroundRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RunPlaygroundJob — the admin playground executor. IMAGE runs synchronously (store + record cost
 * and time); VIDEO only submits the async task and hands off to the poller. Never charges: no
 * credit ledger row is ever written.
 */
class RunPlaygroundJobTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ark.ap-southeast.bytepluses.com/api/v3';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BASE);
        config()->set('services.byteplus.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    private function runJob(PlaygroundRun $run): void
    {
        (new RunPlaygroundJob($run->id))->handle(
            app(PlaygroundImageRunner::class),
            app(VideoProviderRouter::class),
            app(MediaStorage::class),
        );
    }

    public function test_an_image_run_stores_the_result_and_records_time_and_cost(): void
    {
        $png = "\x89PNG\r\n\x1a\nPLAYGROUND";
        Http::fake([self::BASE.'/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $run = PlaygroundRun::factory()->create([
            'kind' => PlaygroundRun::KIND_IMAGE,
            'provider' => PlaygroundRun::PROVIDER_BYTEPLUS,
            'model_id' => 'seedream-5-0-260128',
            'price_hint_micro_usd' => 35_000, // flat-rate price to display as cost
        ]);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNotNull($run->result_path);
        $this->assertSame(35_000, $run->cost_micro_usd);
        $this->assertSame(PlaygroundRun::COST_SOURCE_FLAT_RATE, $run->cost_source);
        $this->assertNotNull($run->duration_ms);
        Storage::disk('s3')->assertExists($run->result_path);

        // A playground run NEVER charges.
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_an_image_run_marks_failed_when_the_provider_errors(): void
    {
        Http::fake([self::BASE.'/images/generations' => Http::response(['error' => ['message' => 'no access']], 404)]);

        $run = PlaygroundRun::factory()->create([
            'kind' => PlaygroundRun::KIND_IMAGE,
            'provider' => PlaygroundRun::PROVIDER_BYTEPLUS,
            'model_id' => 'seedream-5-0-260128',
            'price_hint_micro_usd' => 35_000,
        ]);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(PlaygroundRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error);
        $this->assertNull($run->result_path);
    }

    public function test_a_video_run_submits_the_task_and_dispatches_the_poller(): void
    {
        Bus::fake([PollPlaygroundVideoJob::class]);
        Http::fake([self::BASE.'/contents/generations/tasks' => Http::response(['id' => 'cgt-xyz'], 200)]);

        $run = PlaygroundRun::factory()->video()->create([
            'model_id' => 'dreamina-seedance-2-0-260128',
            'price_hint_micro_usd' => 400_000,
            'meta' => [PlaygroundRun::META_RESOLUTION => '720p', PlaygroundRun::META_DURATION => 5],
        ]);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame('cgt-xyz', $run->provider_task_id);
        $this->assertSame(PlaygroundRun::STATUS_RUNNING, $run->status);
        Bus::assertDispatched(PollPlaygroundVideoJob::class);
    }
}
