<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\FalImageClient;
use App\Domain\Ai\FalModelCatalog;
use App\Domain\Ai\OpenRouterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * FalImageClient: the queue dance (submit → status → result) behind the synchronous provider
 * contract, input images inlined as data URIs, flat-rate cost parsing (admin price or honest
 * unavailable), and the no-spend key/model probes. HTTP is faked throughout.
 */
class FalImageClientTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'fal-ai/krea-2/turbo';
    private const SUBMIT = 'https://queue.fal.run/'.self::MODEL;
    private const REQUEST = 'req-img1';
    private const REQUEST_BASE = self::SUBMIT.'/requests/'.self::REQUEST;
    private const STATUS = self::REQUEST_BASE.'/status';
    private const RESULT = self::REQUEST_BASE.'/response';
    private const IMAGE_URL = 'https://v3.fal.media/files/out.png';
    private const INPUT_URL = 'https://media.test/input.png';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.fal.api_key', 'fal-test-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);
        Sleep::fake();
    }

    private function client(): FalImageClient
    {
        return app(FalImageClient::class);
    }

    public function test_the_queue_dance_returns_the_result_and_the_image_downloads(): void
    {
        $png = "\x89PNG\r\n\x1a\nOUT";
        Http::fake([
            self::STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::RESULT => Http::response(['images' => [['url' => self::IMAGE_URL, 'content_type' => 'image/png']]], 200),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
            self::IMAGE_URL => Http::response($png, 200),
        ]);

        $client = $this->client();
        $response = $client->callWithFallback('op', self::MODEL, null, fn (string $m): array => ['model' => $m, 'prompt' => 'a red apple']);

        $this->assertSame(self::REQUEST, $client->extractGenerationId($response));
        [$bytes, $mime] = $client->extractImage($response);
        $this->assertSame($png, $bytes);
        $this->assertSame('image/png', $mime);

        // The submit carries fal's Key auth and the prompt; the model id lives in the URL path.
        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT
            && $req->hasHeader('Authorization', 'Key fal-test-key')
            && $req->data()['prompt'] === 'a red apple'
            && ! isset($req->data()['model']));
    }

    public function test_the_submit_replys_status_and_response_urls_are_authoritative(): void
    {
        // fal may route a request off the literal model path (e.g. a shared base app) — the urls
        // it returns on submit win over anything we would construct.
        $statusUrl = 'https://queue.fal.run/fal-ai/krea-2/requests/'.self::REQUEST.'/status';
        $resultUrl = 'https://queue.fal.run/fal-ai/krea-2/requests/'.self::REQUEST.'/response';

        Http::fake([
            $statusUrl => Http::response(['status' => 'COMPLETED'], 200),
            $resultUrl => Http::response(['images' => [['url' => self::IMAGE_URL]]], 200),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST, 'status_url' => $statusUrl, 'response_url' => $resultUrl], 200),
        ]);

        $response = $this->client()->callWithFallback('op', self::MODEL, null, fn (string $m): array => ['model' => $m, 'prompt' => 'x']);

        $this->assertSame(self::IMAGE_URL, $response['images'][0]['url']);
        Http::assertSent(fn ($req) => $req->url() === $resultUrl);
    }

    public function test_a_route_mismatch_on_the_result_falls_back_to_the_bare_request_url(): void
    {
        Http::fake([
            self::STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::RESULT => Http::response(['detail' => 'Method Not Allowed'], 405),
            self::REQUEST_BASE => Http::response(['images' => [['url' => self::IMAGE_URL]]], 200),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
        ]);

        $response = $this->client()->callWithFallback('op', self::MODEL, null, fn (string $m): array => ['model' => $m, 'prompt' => 'x']);

        $this->assertSame(self::IMAGE_URL, $response['images'][0]['url']);
    }

    public function test_a_broken_status_route_is_a_classified_error_not_a_silent_terminal(): void
    {
        Http::fake([
            self::STATUS => Http::response(['detail' => 'Method Not Allowed'], 405),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
        ]);

        try {
            $this->client()->callWithFallback('op', self::MODEL, null, fn (string $m): array => ['model' => $m, 'prompt' => 'x']);
            $this->fail('Expected an OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertStringContainsString('status poll error (405)', $e->getMessage());
        }
    }

    public function test_input_images_are_inlined_as_data_uris(): void
    {
        Http::fake([
            self::STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::RESULT => Http::response(['images' => [['url' => self::IMAGE_URL]]], 200),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
            '*' => Http::response("\x89PNG\r\n\x1a\nIN", 200),
        ]);

        $this->client()->callWithFallback('op', self::MODEL, null, fn (string $m): array => [
            'model' => $m,
            'prompt' => 'edit this',
            'image_url' => self::INPUT_URL,
            'image_urls' => [self::INPUT_URL],
        ]);

        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT
            && str_starts_with((string) ($req->data()['image_url'] ?? ''), 'data:image/')
            && str_starts_with((string) ($req->data()['image_urls'][0] ?? ''), 'data:image/'));
    }

    public function test_a_failed_result_throws_with_the_detail(): void
    {
        Http::fake([
            self::STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::RESULT => Http::response(['detail' => 'prompt rejected'], 422),
            self::SUBMIT => Http::response(['request_id' => self::REQUEST], 200),
        ]);

        try {
            $this->client()->callWithFallback('op', self::MODEL, null, fn (string $m): array => ['model' => $m, 'prompt' => 'x']);
            $this->fail('Expected an OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertStringContainsString('prompt rejected', $e->getMessage());
        }
    }

    public function test_parse_cost_is_the_flat_rate_price_or_honestly_unavailable(): void
    {
        $client = $this->client();

        $priced = $client->parseCost([], 15_000);
        $this->assertTrue($priced->available);
        $this->assertSame(0.015, $priced->costUsd);

        $this->assertFalse($client->parseCost([], null)->available);
        $this->assertFalse($client->parseCost([], 0)->available);
    }

    public function test_check_connection_accepts_the_key_on_an_authenticated_4xx(): void
    {
        Http::fake(['https://queue.fal.run/*' => Http::response(['detail' => 'Not found'], 404)]);

        $this->assertTrue($this->client()->checkConnection()['ok']); // authenticated 4xx proves the key
    }

    public function test_check_connection_classifies_a_rejected_or_missing_key(): void
    {
        Http::fake(['https://queue.fal.run/*' => Http::response(['detail' => 'Unauthorized'], 401)]);
        $this->assertSame('invalid_key', $this->client()->checkConnection()['reason']);

        config()->set('services.fal.api_key', '');
        $this->assertSame('not_configured', $this->client()->checkConnection()['reason']);
    }

    public function test_check_model_consults_the_public_catalog(): void
    {
        Http::fake([
            'https://queue.fal.run/*' => Http::response(['detail' => 'Not found'], 404),
            'https://fal.ai/api/models*' => Http::response(['items' => [['id' => self::MODEL, 'title' => 'Krea 2 Turbo']]], 200),
        ]);

        $this->assertTrue($this->client()->checkModel(self::MODEL)['ok']);
        $this->assertSame('model_not_found', $this->client()->checkModel('fal-ai/does-not-exist')['reason']);
    }

    public function test_the_catalog_lists_models_and_filters_deprecated_ones(): void
    {
        Http::fake([
            'https://fal.ai/api/models*' => Http::sequence()
                ->push(['items' => [
                    ['id' => 'fal-ai/krea-2/turbo', 'title' => 'Krea 2 Turbo'],
                    ['id' => 'fal-ai/old-model', 'title' => 'Old', 'deprecated' => true],
                ]], 200)
                ->push(['items' => []], 200),
        ]);

        $options = app(FalModelCatalog::class)->options([FalModelCatalog::CAT_TEXT_TO_IMAGE]);

        $this->assertSame(['fal-ai/krea-2/turbo' => 'fal-ai/krea-2/turbo — Krea 2 Turbo'], $options);
    }

    public function test_the_catalog_degrades_to_empty_when_unreachable(): void
    {
        // The picker then falls back to the pinned suggestions — never an admin-screen error.
        Http::fake(['https://fal.ai/api/models*' => Http::response('down', 500)]);

        $this->assertSame([], app(FalModelCatalog::class)->options([FalModelCatalog::CAT_IMAGE_TO_VIDEO]));
    }
}
