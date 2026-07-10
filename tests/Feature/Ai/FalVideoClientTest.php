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
    // The endpoint's public OpenAPI document (drives the schema-shaped body). Unavailable (500)
    // in the legacy-shape tests so the body degrades to prompt + images.
    private const OPENAPI = 'https://fal.ai/api/openapi/queue/openapi.json*';

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

    /** A happy-horse-shaped OpenAPI document: enum knobs + prompt/image caps. */
    private function openApiDoc(): array
    {
        return [
            'paths' => ['/' => ['post' => ['requestBody' => ['content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/VideoInput'],
            ]]]]]],
            'components' => ['schemas' => ['VideoInput' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'maxLength' => 30],
                    'image_urls' => ['type' => 'array', 'maxItems' => 2],
                    'duration' => ['type' => 'integer', 'enum' => [3, 5, 10, 15], 'default' => 5],
                    'resolution' => ['type' => 'string', 'enum' => ['720p', '1080p']],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['16:9', '9:16', '1:1']],
                ],
                'required' => ['prompt', 'image_urls'],
            ]]],
        ];
    }

    public function test_submit_returns_a_composite_task_id_and_sends_a_data_uri_with_key_auth(): void
    {
        Http::fake([
            self::OPENAPI => Http::response([], 500),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
            self::FRAME_URL => Http::response("\x89PNG\r\n\x1a\nREF", 200),
        ]);

        $taskId = $this->client()->submitTask(self::MODEL, 'animate this frame', [self::FRAME_URL]);

        $this->assertSame(self::TASK, $taskId);
        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT
            && $req->hasHeader('Authorization', 'Key fal-test-key')
            && str_starts_with((string) ($req->data()['image_url'] ?? ''), 'data:image/'));
    }

    public function test_the_task_id_uses_the_queue_app_from_the_submit_replys_status_url(): void
    {
        // fal routes nested model ids off a shorter base app — the reply's status_url is the truth.
        $app = 'fal-ai/kling-video';
        Http::fake([
            self::OPENAPI => Http::response([], 500),
            self::SUBMIT => Http::response([
                'request_id' => self::REQUEST,
                'status_url' => 'https://queue.fal.run/'.$app.'/requests/'.self::REQUEST.'/status',
            ], 200),
            'https://queue.fal.run/'.$app.'/requests/'.self::REQUEST.'/status' => Http::response(['status' => 'IN_PROGRESS'], 200),
        ]);

        $taskId = $this->client()->submitTask(self::MODEL, 'prompt', []);
        $this->assertSame($app.'|'.self::REQUEST, $taskId);

        // The poll then hits the app fal ACTUALLY routed the request under (no 404/405 spin).
        $task = $this->client()->pollTask($taskId);
        $this->assertSame('processing', $task['status']);
        Http::assertSent(fn ($req) => $req->url() === 'https://queue.fal.run/'.$app.'/requests/'.self::REQUEST.'/status');
    }

    public function test_submit_maps_duration_resolution_and_ratio_onto_the_endpoints_own_schema(): void
    {
        Http::fake([
            self::OPENAPI => Http::response($this->openApiDoc(), 200),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
            self::FRAME_URL => Http::response("\x89PNG\r\n\x1a\nREF", 200),
        ]);

        $this->client()->submitTask(self::MODEL, str_repeat('p', 40), [self::FRAME_URL, self::FRAME_URL, self::FRAME_URL], [
            'resolution' => '480p',        // not offered → nearest allowed (720p), never a 422
            'duration_seconds' => 120,     // above the enum → the model's max (15, as an INT)
            'ratio' => '16:9',             // exact enum match → sent as aspect_ratio
        ]);

        Http::assertSent(function ($req): bool {
            if ($req->url() !== self::SUBMIT) {
                return false;
            }
            $data = $req->data();

            return $data['duration'] === 15
                && $data['resolution'] === '720p'
                && $data['aspect_ratio'] === '16:9'
                && count($data['image_urls']) === 2          // trimmed to the schema's maxItems
                && ! array_key_exists('image_url', $data)    // the schema declares image_urls only
                && mb_strlen((string) $data['prompt']) === 30; // truncated to the schema's maxLength
        });
    }

    public function test_submit_error_throws_a_classified_exception(): void
    {
        Http::fake([
            self::OPENAPI => Http::response([], 500),
            self::SUBMIT => Http::response(['detail' => 'invalid input'], 422),
        ]);

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
