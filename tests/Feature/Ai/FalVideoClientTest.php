<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\FalVideoClient;
use App\Domain\Ai\OpenRouterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FalVideoClient: submit returns a COMPOSITE task id ("{model}|{request_id}") and sends the input
 * frame as a base64 data URI under fal's `Authorization: Key …` scheme; poll normalizes fal's
 * queue statuses (IN_PROGRESS → processing, COMPLETED+video.url → succeeded, anything else →
 * failed in the shared TERMINAL_FAILURE vocabulary). HTTP is faked throughout.
 */
class FalVideoClientTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video';
    private const SUBMIT = 'https://queue.fal.run/'.self::MODEL;
    private const REQUEST = 'req-1';
    private const TASK = self::MODEL.'|'.self::REQUEST;
    private const STATUS = self::SUBMIT.'/requests/'.self::REQUEST.'/status';
    private const RESULT = self::SUBMIT.'/requests/'.self::REQUEST.'/response';
    private const FRAME_URL = 'https://media.test/frame.png';
    private const VIDEO_URL = 'https://cdn.fal/clip.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.fal.api_key', 'fal-test-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);
    }

    private function client(): FalVideoClient
    {
        return app(FalVideoClient::class);
    }

    public function test_submit_returns_a_composite_task_id_and_sends_a_data_uri_with_key_auth(): void
    {
        Http::fake([
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
            self::FRAME_URL => Http::response("\x89PNG\r\n\x1a\nREF", 200),
        ]);

        $taskId = $this->client()->submitTask(self::MODEL, 'animate this frame', [self::FRAME_URL]);

        $this->assertSame(self::TASK, $taskId);
        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT
            && $req->hasHeader('Authorization', 'Key fal-test-key')
            && str_starts_with((string) ($req->data()['image_url'] ?? ''), 'data:image/'));
    }

    public function test_submit_error_throws_a_classified_exception(): void
    {
        Http::fake([self::SUBMIT => Http::response(['detail' => 'invalid input'], 422)]);

        $this->expectException(OpenRouterException::class);
        $this->client()->submitTask(self::MODEL, 'prompt', []);
    }

    public function test_an_in_flight_poll_passes_through_as_processing(): void
    {
        Http::fake([self::STATUS => Http::response(['status' => 'IN_PROGRESS'], 200)]);

        $task = $this->client()->pollTask(self::TASK);

        $this->assertSame('processing', $task['status']);
        $this->assertFalse($this->client()->succeeded($task));
        $this->assertNotContains($task['status'], VideoGenerationProvider::TERMINAL_FAILURE);
    }

    public function test_a_completed_poll_fetches_the_result_and_normalizes_the_video_url(): void
    {
        Http::fake([
            self::STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::RESULT => Http::response(['video' => ['url' => self::VIDEO_URL]], 200),
        ]);

        $task = $this->client()->pollTask(self::TASK);

        $this->assertTrue($this->client()->succeeded($task));
        $this->assertSame(self::VIDEO_URL, $task['content']['video_url']);
    }

    public function test_a_completed_poll_without_a_video_url_normalizes_to_failed(): void
    {
        Http::fake([
            self::STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::RESULT => Http::response(['detail' => 'nsfw filter triggered'], 200),
        ]);

        $task = $this->client()->pollTask(self::TASK);

        $this->assertSame('failed', $task['status']);
        $this->assertContains($task['status'], VideoGenerationProvider::TERMINAL_FAILURE);
        $this->assertStringContainsString('nsfw', (string) $task['error']['message']);
    }

    public function test_an_unknown_terminal_status_normalizes_to_failed(): void
    {
        Http::fake([self::STATUS => Http::response(['status' => 'CANCELLED'], 200)]);

        $task = $this->client()->pollTask(self::TASK);

        $this->assertSame('failed', $task['status']);
    }

    public function test_download_video_returns_the_bytes(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42CLIP";
        Http::fake([self::VIDEO_URL => Http::response($mp4, 200)]);

        $this->assertSame($mp4, $this->client()->downloadVideo(self::VIDEO_URL));
    }
}
