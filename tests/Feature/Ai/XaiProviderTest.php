<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\ProviderRouter;
use App\Domain\Ai\TryOnGenerationCaller;
use App\Domain\Ai\XaiImageClient;
use App\Models\AiModel;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * xAI/Grok as a third image provider: provider routing, the no-spend GET /models probe,
 * image + (flat-rate) cost extraction, and the caller routing an xai-provider config to the
 * xAI /images/generations endpoint with the TEXT-TO-IMAGE body (model + prompt only — no
 * input image, no size/quality) — all without touching the OpenRouter/BytePlus paths.
 */
class XaiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.x.ai/v1';
    private const GEN = self::BASE.'/images/generations';
    private const MODELS = self::BASE.'/models';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.xai.api_key', 'xai-real-key');
        config()->set('services.xai.base_url', self::BASE);
        config()->set('services.xai.timeout', 30);
        Sleep::fake();
    }

    public function test_router_picks_the_xai_client(): void
    {
        $this->assertInstanceOf(
            XaiImageClient::class,
            app(ProviderRouter::class)->for(ImageGenerationProvider::PROVIDER_XAI),
        );
    }

    public function test_check_connection_probes_models_without_spending(): void
    {
        Http::fake([self::MODELS => Http::sequence()
            ->push([], 401)
            ->push(['data' => [['id' => 'grok-2-image']]], 200)]);

        $bad = app(XaiImageClient::class)->checkConnection('xai-bad');
        $this->assertFalse($bad['ok']);
        $this->assertSame('invalid_key', $bad['reason']);

        $good = app(XaiImageClient::class)->checkConnection('xai-good');
        $this->assertTrue($good['ok']);

        // The probe is a GET against /models — it never hits the paid generation endpoint.
        Http::assertSent(fn ($req) => $req->method() === 'GET' && str_ends_with($req->url(), '/models'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/images/generations'));
    }

    public function test_check_connection_flags_placeholder_without_calling(): void
    {
        config()->set('services.xai.api_key', 'REPLACE_WITH_REAL_XAI_KEY');

        $result = app(XaiImageClient::class)->checkConnection();

        $this->assertFalse($result['ok']);
        $this->assertSame('not_configured', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_extract_image_and_flat_rate_cost(): void
    {
        $client = app(XaiImageClient::class);
        $png = "\x89PNG\r\n\x1a\nBYTES";

        [$bytes] = $client->extractImage(['data' => [['b64_json' => base64_encode($png)]]]);
        $this->assertSame($png, $bytes);

        // Cost is the admin-set per-image hint; fails closed with no positive price.
        $this->assertTrue($client->parseCost([], 70_000)->available);
        $this->assertFalse($client->parseCost([], null)->available);
        $this->assertFalse($client->parseCost([], 0)->available);
    }

    public function test_check_model_reports_404_as_no_access(): void
    {
        Http::fake([self::MODELS.'/grok-2-image' => Http::response(['error' => ['message' => 'The model does not exist.']], 404)]);

        $result = app(XaiImageClient::class)->checkModel('grok-2-image');

        $this->assertFalse($result['ok']);
        $this->assertSame('model_not_found', $result['reason']);
    }

    public function test_check_model_reports_reachable_on_200(): void
    {
        Http::fake([self::MODELS.'/grok-2-image' => Http::response(['id' => 'grok-2-image'], 200)]);

        $result = app(XaiImageClient::class)->checkModel('grok-2-image');

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['reason']);
    }

    public function test_resolver_carries_the_xai_provider_for_banners(): void
    {
        $this->seed(AiControlPlaneSeeder::class);

        // Activate + default the seeded (inactive) Grok banner model.
        AiModel::query()->where('operation_key', 'banner_generation')
            ->where('model_id', 'grok-2-image')
            ->firstOrFail()
            ->forceFill(['is_active' => true, 'is_default' => true, 'cost_hint_micro_usd' => 70_000])
            ->save();

        $config = app(AiOperationResolver::class)->for('banner_generation');

        $this->assertSame(ImageGenerationProvider::PROVIDER_XAI, $config->provider);
        $this->assertSame('grok-2-image', $config->model);
    }

    public function test_caller_routes_an_xai_config_to_text_to_image(): void
    {
        $png = "\x89PNG\r\n\x1a\nGROK";
        Http::fake([self::GEN => Http::response(['model' => 'grok-2-image', 'data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $config = new OperationConfig(
            operationKey: 'try_on_generation',
            model: 'grok-2-image',
            fallbackModel: null,
            systemPrompt: 'sys',
            userPrompt: 'wear {{product_name}}',
            imageQuality: 'high',
            aspectRatio: '3:4',
            params: ['seed' => 7],
            creditMultiplier: null,
            promptVersion: 1,
            estimatedCostMicroUsd: 70_000,
            inputSchema: null,
            provider: ImageGenerationProvider::PROVIDER_XAI,
        );

        $result = app(TryOnGenerationCaller::class)->generate(
            $config,
            ImagePayload::fromUrl('https://cdn.test/shopper.jpg'),
            ImagePayload::fromUrl('https://cdn.test/product.jpg'),
            ['product_name' => 'Ring', 'variant' => '', 'height' => ''],
        );

        $this->assertSame($png, $result->imageBytes);
        $this->assertSame('grok-2-image', $result->modelUsed);
        $this->assertTrue($result->cost->available); // flat rate from the estimate

        // The body is text-to-image: model + prompt + response_format + n, and NO input
        // image / size / quality (xAI's endpoint rejects those).
        Http::assertSent(function ($req) {
            $body = $req->data();

            return str_contains($req->url(), '/images/generations')
                && $body['model'] === 'grok-2-image'
                && str_contains($body['prompt'], 'wear Ring')
                && $body['response_format'] === 'b64_json'
                && $body['n'] === 1
                && ! array_key_exists('image', $body)
                && ! array_key_exists('size', $body)
                && ! array_key_exists('quality', $body);
        });
    }

    public function test_router_still_rejects_an_unknown_provider(): void
    {
        $this->expectException(OpenRouterException::class);
        app(ProviderRouter::class)->for('nope');
    }
}
