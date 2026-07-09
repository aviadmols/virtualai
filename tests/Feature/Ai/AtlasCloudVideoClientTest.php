<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AtlasCloudVideoClient;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\OpenRouterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AtlasCloudVideoClient — the async AtlasCloud video task API: submit POSTs /model/generateVideo
 * (an http reference image is downloaded + sent as a base64 data URI) and returns data.id; poll
 * GETs /model/prediction/{id} and NORMALIZES the response to the shared shape (completed ->
 * succeeded, outputs[0] -> content.video_url, error -> error.message). HTTP is faked — no real call.
 */
class AtlasCloudVideoClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.atlascloud.ai/api/v1';
    private const SUBMIT = self::BASE.'/model/generateVideo';
    private const TASK = 'pred-1';
    private const PREDICTION = self::BASE.'/model/prediction/'.self::TASK;
    private const VIDEO_URL = 'https://cdn/x.mp4';
    private const REF_IMAGE = 'https://cdn.test/frame.png';
    private const MODEL = 'bytedance/seedance-2.0/reference-to-video';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.atlascloud.api_key', 'ac-real-key');
        config()->set('services.atlascloud.base_url', self::BASE);
        config()->set('services.atlascloud.timeout', 30);
    }

    private function client(): AtlasCloudVideoClient
    {
        return app(AtlasCloudVideoClient::class);
    }

    public function test_submit_returns_the_prediction_id_and_sends_a_base64_reference_image(): void
    {
        Http::fake([
            self::SUBMIT => Http::response(['data' => ['id' => self::TASK]], 200),
            self::REF_IMAGE => Http::response("\x89PNG\r\n\x1a\nREF", 200), // the reference image bytes
        ]);

        $id = $this->client()->submitTask(
            self::MODEL,
            'the model walks forward',
            [self::REF_IMAGE],
            ['resolution' => '720p', 'duration_seconds' => 5, 'ratio' => 'adaptive'],
        );

        $this->assertSame(self::TASK, $id);

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/model/generateVideo')) {
                return false;
            }

            $body = $req->data();

            // The private-disk image must reach AtlasCloud as a base64 data URI, not a bare url.
            return $req->method() === 'POST'
                && $body['model'] === self::MODEL
                && str_starts_with($body['reference_images'][0], 'data:')
                && $body['resolution'] === '720p'
                && $body['duration'] === 5
                && $body['ratio'] === 'adaptive'
                && $body['watermark'] === false;
        });
    }

    public function test_poll_normalizes_a_completed_prediction_to_succeeded(): void
    {
        Http::fake([self::PREDICTION => Http::response([
            'data' => ['status' => 'completed', 'outputs' => [self::VIDEO_URL]],
        ], 200)]);

        $client = $this->client();
        $task = $client->pollTask(self::TASK);

        $this->assertTrue($client->succeeded($task));
        $this->assertSame('succeeded', $task['status']);
        $this->assertSame(self::VIDEO_URL, $task['content']['video_url']);
    }

    public function test_poll_normalizes_a_failed_prediction_onto_the_shared_terminal_state(): void
    {
        Http::fake([self::PREDICTION => Http::response([
            'data' => ['status' => 'failed', 'error' => 'content policy violation'],
        ], 200)]);

        $client = $this->client();
        $task = $client->pollTask(self::TASK);

        $this->assertFalse($client->succeeded($task));
        $this->assertSame('failed', $task['status']);
        $this->assertContains($task['status'], VideoGenerationProvider::TERMINAL_FAILURE);
        $this->assertSame('content policy violation', $task['error']['message']);
    }

    public function test_poll_leaves_a_processing_state_untouched_so_the_caller_reschedules(): void
    {
        Http::fake([self::PREDICTION => Http::response(['data' => ['status' => 'processing']], 200)]);

        $client = $this->client();
        $task = $client->pollTask(self::TASK);

        $this->assertFalse($client->succeeded($task));
        $this->assertSame('processing', $task['status']);
        $this->assertNotContains($task['status'], VideoGenerationProvider::TERMINAL_FAILURE);
    }

    public function test_submit_error_throws_a_classified_exception(): void
    {
        Http::fake([self::SUBMIT => Http::response(['error' => ['message' => 'bad model']], 400)]);

        $this->expectException(OpenRouterException::class);

        $this->client()->submitTask('nope', 'x', []);
    }

    public function test_download_video_returns_the_bytes(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42FAKE";
        Http::fake([self::VIDEO_URL => Http::response($mp4, 200)]);

        $this->assertSame($mp4, $this->client()->downloadVideo(self::VIDEO_URL));
    }
}
