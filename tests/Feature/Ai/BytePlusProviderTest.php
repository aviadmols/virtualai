<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\BytePlusImageClient;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterClient;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\ProviderRouter;
use App\Domain\Ai\TryOnGenerationCaller;
use App\Models\AiModel;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * BytePlus/Seedream as a second image provider: provider routing, the no-spend
 * connection probe, image + (flat-rate) cost extraction, and the caller routing a
 * byteplus-provider config to the BytePlus endpoint with the right image+prompt body —
 * all without touching the OpenRouter path.
 */
class BytePlusProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ark.ap-southeast.bytepluses.com/api/v3';
    private const GEN = self::BASE.'/images/generations';
    private const OR_BASE = 'https://openrouter.ai/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::BASE);
        config()->set('services.byteplus.timeout', 30);
        config()->set('services.byteplus.probe_model', 'seedream-5-0-260128');
        Sleep::fake();
    }

    public function test_router_picks_the_right_client(): void
    {
        $router = app(ProviderRouter::class);

        $this->assertInstanceOf(BytePlusImageClient::class, $router->for(ImageGenerationProvider::PROVIDER_BYTEPLUS));
        $this->assertInstanceOf(OpenRouterClient::class, $router->for(ImageGenerationProvider::PROVIDER_OPENROUTER));
    }

    public function test_router_rejects_an_unknown_provider(): void
    {
        $this->expectException(OpenRouterException::class);
        app(ProviderRouter::class)->for('nope');
    }

    public function test_check_connection_401_invalid_and_200_ok(): void
    {
        Http::fake([self::GEN => Http::sequence()
            ->push([], 401)
            ->push(['data' => [['b64_json' => base64_encode('x')]]], 200)]);

        $bad = app(BytePlusImageClient::class)->checkConnection('bp-bad');
        $this->assertFalse($bad['ok']);
        $this->assertSame('invalid_key', $bad['reason']);

        $good = app(BytePlusImageClient::class)->checkConnection('bp-good');
        $this->assertTrue($good['ok']);
    }

    public function test_check_connection_flags_placeholder_without_calling(): void
    {
        config()->set('services.byteplus.api_key', 'REPLACE_WITH_REAL_BYTEPLUS_KEY');

        $result = app(BytePlusImageClient::class)->checkConnection();

        $this->assertFalse($result['ok']);
        $this->assertSame('not_configured', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_extract_image_and_flat_rate_cost(): void
    {
        $client = app(BytePlusImageClient::class);
        $png = "\x89PNG\r\n\x1a\nBYTES";

        [$bytes] = $client->extractImage(['data' => [['b64_json' => base64_encode($png)]]]);
        $this->assertSame($png, $bytes);

        // Cost is the admin-set per-image hint; fails closed with no positive price.
        $this->assertTrue($client->parseCost([], 40_000)->available);
        $this->assertFalse($client->parseCost([], null)->available);
        $this->assertFalse($client->parseCost([], 0)->available);
    }

    public function test_check_model_reports_404_as_no_access(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        Http::fake([self::GEN => Http::response(['error' => ['message' => 'The model or endpoint seedream-4-0-250828 does not exist or you do not have access to it.']], 404)]);

        $result = app(BytePlusImageClient::class)->checkModel('seedream-4-0-250828');

        $this->assertFalse($result['ok']);
        $this->assertSame('model_not_found', $result['reason']);
    }

    public function test_check_model_reports_reachable_on_200(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        Http::fake([self::GEN => Http::response(['model' => 'seedream-5-0-260128', 'data' => [['b64_json' => base64_encode('x')]]], 200)]);

        $result = app(BytePlusImageClient::class)->checkModel('seedream-5-0-260128');

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['reason']);
    }

    public function test_resolver_carries_the_byteplus_provider(): void
    {
        $this->seed(AiControlPlaneSeeder::class);

        // Activate + default the seeded (inactive) Seedream model with a cost hint.
        $model = AiModel::query()->where('operation_key', 'try_on_generation')
            ->where('model_id', 'seedream-5-0-260128')->firstOrFail();
        $model->forceFill(['is_active' => true, 'is_default' => true, 'cost_hint_micro_usd' => 40_000])->save();

        $config = app(AiOperationResolver::class)->for('try_on_generation');

        $this->assertSame(ImageGenerationProvider::PROVIDER_BYTEPLUS, $config->provider);
        $this->assertSame('seedream-5-0-260128', $config->model);
        // The fallback stays on OpenRouter (the seeded Gemini fallback) — proving the
        // resolver carries a per-model provider for the fallback too (cross-provider).
        $this->assertSame('google/gemini-2.5-flash-image', $config->fallbackModel);
        $this->assertSame(ImageGenerationProvider::PROVIDER_OPENROUTER, $config->fallbackProvider);
    }

    public function test_byteplus_failure_falls_back_to_openrouter_across_providers(): void
    {
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::OR_BASE);
        config()->set('services.openrouter.timeout', 30);

        $gemini = "\x89PNG\r\n\x1a\nGEMINI";
        $dataUrl = 'data:image/png;base64,'.base64_encode($gemini);

        // BytePlus 404s (the exact failure the merchant hit); OpenRouter/Gemini then serves it.
        Http::fake([
            self::GEN => Http::response(['error' => ['message' => 'The model or endpoint does not exist or you do not have access to it.']], 404),
            self::OR_BASE.'/chat/completions' => Http::response([
                'id' => 'gen-fallback',
                'model' => 'google/gemini-2.5-flash-image',
                'usage' => ['cost' => 0.039],
                'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'images' => [['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]]]]],
            ], 200),
        ]);

        $config = new OperationConfig(
            operationKey: 'try_on_generation',
            model: 'seedream-5-0-260128',
            fallbackModel: 'google/gemini-2.5-flash-image',
            systemPrompt: 'sys',
            userPrompt: 'wear {{product_name}}',
            imageQuality: 'high',
            aspectRatio: '3:4',
            params: ['seed' => 7],
            creditMultiplier: null,
            promptVersion: 1,
            estimatedCostMicroUsd: 40_000,
            inputSchema: null,
            provider: ImageGenerationProvider::PROVIDER_BYTEPLUS,
            fallbackProvider: ImageGenerationProvider::PROVIDER_OPENROUTER,
        );

        $result = app(TryOnGenerationCaller::class)->generate(
            $config,
            ImagePayload::fromUrl('https://cdn.test/shopper.jpg'),
            ImagePayload::fromUrl('https://cdn.test/product.jpg'),
            ['product_name' => 'Ring', 'variant' => '', 'height' => ''],
        );

        // The fallback provider produced the image — the shopper still gets a result.
        $this->assertSame($gemini, $result->imageBytes);
        $this->assertSame('google/gemini-2.5-flash-image', $result->modelUsed);
        $this->assertTrue($result->cost->available);

        // BytePlus was attempted first, then OpenRouter served the fallback.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/images/generations'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/chat/completions')
            && $req['model'] === 'google/gemini-2.5-flash-image');
    }

    public function test_caller_routes_a_byteplus_config_to_seedream(): void
    {
        $png = "\x89PNG\r\n\x1a\nSEEDREAM";
        Http::fake([self::GEN => Http::response(['model' => 'seedream-5-0-260128', 'data' => [['b64_json' => base64_encode($png)]]], 200)]);

        $config = new OperationConfig(
            operationKey: 'try_on_generation',
            model: 'seedream-5-0-260128',
            fallbackModel: null,
            systemPrompt: 'sys',
            userPrompt: 'wear {{product_name}}',
            imageQuality: 'high',
            aspectRatio: '3:4',
            params: ['seed' => 7],
            creditMultiplier: null,
            promptVersion: 1,
            estimatedCostMicroUsd: 40_000,
            inputSchema: null,
            provider: ImageGenerationProvider::PROVIDER_BYTEPLUS,
        );

        $result = app(TryOnGenerationCaller::class)->generate(
            $config,
            ImagePayload::fromUrl('https://cdn.test/shopper.jpg'),
            ImagePayload::fromUrl('https://cdn.test/product.jpg'),
            ['product_name' => 'Ring', 'variant' => '', 'height' => ''],
        );

        $this->assertSame($png, $result->imageBytes);
        $this->assertSame('seedream-5-0-260128', $result->modelUsed);
        $this->assertTrue($result->cost->available); // from the cost hint (flat rate)

        Http::assertSent(fn ($req) => str_contains($req->url(), '/images/generations')
            && $req['model'] === 'seedream-5-0-260128'
            && is_array($req['image']) && count($req['image']) === 2
            && str_contains($req['prompt'], 'wear Ring')
            && $req['size'] === '2K'
            && $req['seed'] === 7);
    }
}
