<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\KlingVideoClient;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\VideoProviderRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * KlingVideoClient: the async submit → poll → download contract shared by every video provider.
 * The endpoint is chosen by whether an input frame is given (image2video vs text2video), the task
 * id carries that endpoint so the poll routes back to it, and the poll result is NORMALIZED onto
 * the shape every poller already reads. HTTP is faked throughout.
 */
class KlingVideoClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-singapore.klingai.com';

    private const MODEL = 'kling-v2-5-turbo';

    private const SUBMIT_I2V = self::BASE.'/v1/videos/image2video';

    private const SUBMIT_T2V = self::BASE.'/v1/videos/text2video';

    private const TASK = 'vid-task-1';

    private const QUERY_I2V = self::SUBMIT_I2V.'/'.self::TASK;

    private const FRAME_URL = 'https://media.test/frame.png';

    private const VIDEO_URL = 'https://cdn.klingai.test/out.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        // The legacy pair (JWT auth); the static-API-key path is covered in KlingImageClientTest.
        config()->set('services.kling.api_key', '');
        config()->set('services.kling.access_key', 'ak-test');
        config()->set('services.kling.secret_key', 'sk-test');
        config()->set('services.kling.base_url', self::BASE);
        config()->set('services.kling.timeout', 30);
        Sleep::fake();
    }

    private function client(): KlingVideoClient
    {
        return app(KlingVideoClient::class);
    }

    /** @param array<string,mixed> $extra */
    private function task(string $status, array $extra = []): array
    {
        return [
            'code' => 0,
            'message' => 'SUCCEED',
            'data' => ['task_id' => self::TASK, 'task_status' => $status] + $extra,
        ];
    }

    public function test_the_video_router_resolves_kling(): void
    {
        $this->assertInstanceOf(
            KlingVideoClient::class,
            app(VideoProviderRouter::class)->for(ImageGenerationProvider::PROVIDER_KLING),
        );
    }

    public function test_an_input_frame_submits_image_to_video_and_the_task_id_carries_the_endpoint(): void
    {
        Http::fake([
            self::SUBMIT_I2V => Http::response($this->task('submitted'), 200),
            self::FRAME_URL => Http::response('FRAMEBYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $taskId = $this->client()->submitTask(
            self::MODEL,
            'slow push-in',
            [self::FRAME_URL],
            ['duration_seconds' => 3, 'ratio' => '16:9', 'mode' => 'pro'],
        );

        // The composite id keeps the endpoint so the poll routes back to the SAME path.
        $this->assertSame('/v1/videos/image2video|'.self::TASK, $taskId);

        Http::assertSent(function ($req): bool {
            if ($req->url() !== self::SUBMIT_I2V) {
                return false;
            }

            $data = $req->data();

            return $data['model_name'] === self::MODEL
                && $data['prompt'] === 'slow push-in'
                // The frame is inlined as RAW base64 (no data: prefix).
                && $data['image'] === base64_encode('FRAMEBYTES')
                // 3s is not a Kling length — it clamps to the nearest allowed (5s), as a STRING.
                && $data['duration'] === '5'
                // image-to-video takes its ratio FROM THE FRAME: Kling rejects aspect_ratio here.
                && ! isset($data['aspect_ratio'])
                && $data['mode'] === 'pro';
        });
    }

    public function test_an_end_frame_rides_as_image_tail_on_image_to_video_only(): void
    {
        Http::fake([
            self::SUBMIT_I2V => Http::response($this->task('submitted'), 200),
            self::SUBMIT_T2V => Http::response($this->task('submitted'), 200),
            self::FRAME_URL => Http::response('FRAMEBYTES', 200, ['Content-Type' => 'image/png']),
            'https://media.test/next.png' => Http::response('NEXTBYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        // i2v: the END frame (the next shot's opening image) rides as image_tail — RAW base64.
        $this->client()->submitTask(
            self::MODEL,
            'slow push-in',
            [self::FRAME_URL],
            ['duration_seconds' => 5, KlingVideoClient::PARAM_IMAGE_TAIL => 'https://media.test/next.png'],
        );

        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT_I2V
            && $req->data()['image'] === base64_encode('FRAMEBYTES')
            && $req->data()['image_tail'] === base64_encode('NEXTBYTES'));

        // t2v (no input frame): the tail param is IGNORED — image_tail is an i2v-only field.
        $this->client()->submitTask(
            self::MODEL,
            'a city at dawn',
            [],
            [KlingVideoClient::PARAM_IMAGE_TAIL => 'https://media.test/next.png'],
        );

        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT_T2V
            && ! isset($req->data()['image_tail']));
    }

    public function test_an_unreadable_tail_is_dropped_but_the_clip_still_submits(): void
    {
        Http::fake([
            self::SUBMIT_I2V => Http::response($this->task('submitted'), 200),
            self::FRAME_URL => Http::response('FRAMEBYTES', 200, ['Content-Type' => 'image/png']),
            'https://media.test/gone.png' => Http::response('', 404),
        ]);

        $taskId = $this->client()->submitTask(
            self::MODEL,
            'slow push-in',
            [self::FRAME_URL],
            [KlingVideoClient::PARAM_IMAGE_TAIL => 'https://media.test/gone.png'],
        );

        $this->assertSame('/v1/videos/image2video|'.self::TASK, $taskId);
        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT_I2V
            && ! isset($req->data()['image_tail']));
    }

    public function test_no_input_frame_submits_text_to_video_and_drops_an_adaptive_ratio(): void
    {
        Http::fake([self::SUBMIT_T2V => Http::response($this->task('submitted'), 200)]);

        $taskId = $this->client()->submitTask(self::MODEL, 'a city at dawn', [], ['ratio' => 'adaptive']);

        $this->assertSame('/v1/videos/text2video|'.self::TASK, $taskId);

        // 'adaptive' is OUR sentinel, not a Kling enum — it is omitted, never sent.
        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT_T2V
            && ! isset($req->data()['aspect_ratio'])
            && ! isset($req->data()['image']));
    }

    public function test_a_real_ratio_is_sent_on_text_to_video(): void
    {
        Http::fake([self::SUBMIT_T2V => Http::response($this->task('submitted'), 200)]);

        $this->client()->submitTask(self::MODEL, 'a city at dawn', [], ['ratio' => '9:16']);

        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT_T2V
            && $req->data()['aspect_ratio'] === '9:16');
    }

    public function test_a_hostile_base_url_never_receives_the_platform_credential(): void
    {
        // base_url is DB-managed (ai_models.base_url) and every request carries the platform's
        // Kling key: an override that is not an https Kling host is DROPPED, and the call falls
        // back to the configured region host. The key can never be redirected off-provider.
        Http::fake([self::SUBMIT_T2V => Http::response($this->task('submitted'), 200)]);

        $this->client()->submitTask(self::MODEL, 'a city at dawn', [], [], 'https://evil.example.com');

        Http::assertSent(fn ($req) => str_starts_with($req->url(), self::BASE));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.example.com'));
    }

    public function test_a_real_kling_region_host_is_honoured(): void
    {
        // The guard must not break the legitimate case: another Kling region host still routes.
        $beijing = 'https://api-beijing.klingai.com';
        Http::fake([$beijing.'/v1/videos/text2video' => Http::response($this->task('submitted'), 200)]);

        $this->client()->submitTask(self::MODEL, 'a city at dawn', [], [], $beijing);

        Http::assertSent(fn ($req) => str_starts_with($req->url(), $beijing));
    }

    public function test_polling_normalizes_processing_succeeded_and_failed(): void
    {
        $client = $this->client();
        $taskId = '/v1/videos/image2video|'.self::TASK;

        // One stub, three consecutive polls — the client sees the task move through its states.
        Http::fake([self::QUERY_I2V => Http::sequence()
            ->push($this->task('processing'), 200)
            ->push($this->task('succeed', ['task_result' => ['videos' => [['url' => self::VIDEO_URL, 'duration' => '5']]]]), 200)
            ->push($this->task('failed', ['task_status_msg' => 'nsfw']), 200)]);

        $processing = $client->pollTask($taskId);
        $this->assertSame('processing', $processing['status']);
        $this->assertFalse($client->succeeded($processing));

        $done = $client->pollTask($taskId);
        $this->assertTrue($client->succeeded($done));
        $this->assertSame(self::VIDEO_URL, $done['content']['video_url']);

        // A terminal failure is a RESULT (never an exception) so the poller can finish the row.
        $failed = $client->pollTask($taskId);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('nsfw', $failed['error']['message']);
    }

    public function test_a_transport_error_on_poll_throws_so_the_caller_reschedules(): void
    {
        Http::fake([self::QUERY_I2V => Http::response(['code' => 5000, 'message' => 'oops'], 500)]);

        try {
            $this->client()->pollTask('/v1/videos/image2video|'.self::TASK);
            $this->fail('Expected an OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_PROVIDER_OUTAGE, $e->errorCode);
        }
    }

    public function test_the_result_video_downloads(): void
    {
        Http::fake([self::VIDEO_URL => Http::response('MP4BYTES', 200)]);

        $this->assertSame('MP4BYTES', $this->client()->downloadVideo(self::VIDEO_URL));
        $this->assertNull($this->client()->downloadVideo('not-a-url'));
    }
}
