<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\BytePlusVideoClient;
use App\Domain\Ai\OpenRouterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BytePlusVideoClient — the async Seedance video task API: submit builds the content array
 * (text + first_frame image) and returns the task id; poll decodes the task; a succeeded task
 * exposes content.video_url which downloads server-side. HTTP is faked — no real ModelArk call.
 */
class BytePlusVideoClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ark.ap-southeast.bytepluses.com/api/v3';
    private const TASKS = self::BASE.'/contents/generations/tasks';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BASE);
        config()->set('services.byteplus.timeout', 30);
    }

    public function test_submit_task_builds_the_content_array_and_returns_the_task_id(): void
    {
        Http::fake([self::TASKS => Http::response(['id' => 'cgt-abc123'], 200)]);

        $id = app(BytePlusVideoClient::class)->submitTask(
            'dreamina-seedance-2-0-260128',
            'model turns to show the jacket',
            ['https://cdn.test/first.png', 'https://cdn.test/ref.png'],
            ['resolution' => '1080p', 'duration_seconds' => 8, 'ratio' => '9:16'],
        );

        $this->assertSame('cgt-abc123', $id);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return str_ends_with($req->url(), '/contents/generations/tasks')
                && $req->method() === 'POST'
                && $body['model'] === 'dreamina-seedance-2-0-260128'
                && $body['content'][0]['type'] === 'text'
                && $body['content'][1]['type'] === 'image_url'
                && $body['content'][1]['role'] === 'first_frame'
                && $body['content'][1]['image_url']['url'] === 'https://cdn.test/first.png'
                && $body['content'][2]['role'] === 'reference_image'
                && $body['resolution'] === '1080p'
                && $body['duration'] === 8
                && $body['ratio'] === '9:16'
                && $body['watermark'] === false;
        });
    }

    public function test_submit_task_text_only_sends_a_single_text_part(): void
    {
        Http::fake([self::TASKS => Http::response(['id' => 'cgt-text'], 200)]);

        app(BytePlusVideoClient::class)->submitTask('seedance', 'a sunrise timelapse', []);

        Http::assertSent(fn ($req) => count($req->data()['content']) === 1 && $req->data()['content'][0]['type'] === 'text');
    }

    public function test_submit_error_throws_a_classified_exception(): void
    {
        Http::fake([self::TASKS => Http::response(['error' => ['message' => 'bad model']], 400)]);

        $this->expectException(OpenRouterException::class);

        app(BytePlusVideoClient::class)->submitTask('nope', 'x', []);
    }

    public function test_poll_task_decodes_a_succeeded_task(): void
    {
        Http::fake([self::TASKS.'/cgt-1' => Http::response([
            'id' => 'cgt-1',
            'status' => 'succeeded',
            'content' => ['video_url' => 'https://cdn.test/out.mp4'],
            'usage' => ['total_tokens' => 97605],
            'created_at' => 1000,
            'updated_at' => 1042,
        ], 200)]);

        $client = app(BytePlusVideoClient::class);
        $task = $client->pollTask('cgt-1');

        $this->assertTrue($client->succeeded($task));
        $this->assertSame('https://cdn.test/out.mp4', $task['content']['video_url']);
        $this->assertSame(97605, $task['usage']['total_tokens']);
    }

    public function test_poll_task_reports_running_without_a_video(): void
    {
        Http::fake([self::TASKS.'/cgt-2' => Http::response(['id' => 'cgt-2', 'status' => 'running'], 200)]);

        $task = app(BytePlusVideoClient::class)->pollTask('cgt-2');

        $this->assertFalse(app(BytePlusVideoClient::class)->succeeded($task));
        $this->assertSame('running', $task['status']);
    }

    public function test_poll_http_error_throws_so_the_caller_can_retry(): void
    {
        Http::fake([self::TASKS.'/cgt-3' => Http::response([], 503)]);

        $this->expectException(OpenRouterException::class);

        app(BytePlusVideoClient::class)->pollTask('cgt-3');
    }

    public function test_download_video_returns_the_bytes(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42FAKE";
        Http::fake(['https://cdn.test/out.mp4' => Http::response($mp4, 200)]);

        $bytes = app(BytePlusVideoClient::class)->downloadVideo('https://cdn.test/out.mp4');

        $this->assertSame($mp4, $bytes);
    }
}
