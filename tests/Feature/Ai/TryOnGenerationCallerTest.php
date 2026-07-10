<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\TryOnGenerationCaller;
use App\Models\AiModel;
use App\Models\AiOperation;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * try_on_generation. HTTP mocked. Proves the result is image BYTES (decoded from
 * the data URL), honours image_quality / aspect_ratio / seed from the bag, and
 * classifies a no-image response as invalid_image.
 */
class TryOnGenerationCallerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://openrouter.ai/api/v1';
    private const PNG_BYTES = "\x89PNG\r\n\x1a\nFAKEIMAGEBYTES";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::BASE);
        config()->set('services.openrouter.timeout', 30);

        // No real backoff sleeps in tests (TS-OPENROUTER-003).
        Sleep::fake();
    }

    private function caller(): TryOnGenerationCaller
    {
        return app(TryOnGenerationCaller::class);
    }

    private function bag()
    {
        return (new AiOperationResolver)->for(AiOperation::KEY_TRY_ON_GENERATION);
    }

    private function images(): array
    {
        $shopper = ImagePayload::fromBytes(self::PNG_BYTES, 'image/png');
        $variant = ImagePayload::fromUrl('https://cdn.example/variant.jpg');

        return [$shopper, $variant];
    }

    private function imageResponse(): array
    {
        $dataUrl = 'data:image/png;base64,'.base64_encode(self::PNG_BYTES);

        return [
            'id' => 'gen-img',
            'model' => 'google/gemini-2.5-flash-image',
            'usage' => ['cost' => 0.039],
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'images' => [['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]],
                ],
            ]],
        ];
    }

    public function test_returns_decoded_image_bytes(): void
    {
        Http::fake([self::BASE.'/chat/completions' => Http::response($this->imageResponse(), 200)]);

        [$shopper, $variant] = $this->images();
        $result = $this->caller()->generate($this->bag(), $shopper, $variant, [
            'product_name' => 'Red Sneaker', 'variant' => 'M / Red', 'height' => 180,
        ]);

        $this->assertSame(self::PNG_BYTES, $result->imageBytes);
        $this->assertSame('image/png', $result->mimeType);
        $this->assertTrue($result->cost->available);
        $this->assertSame(0.039, $result->cost->costUsd);
        $this->assertSame('gen-img', $result->openrouterGenerationId);
    }

    public function test_request_honours_quality_aspect_and_seed_from_the_bag(): void
    {
        Http::fake([self::BASE.'/chat/completions' => Http::response($this->imageResponse(), 200)]);

        [$shopper, $variant] = $this->images();
        $this->caller()->generate($this->bag(), $shopper, $variant, ['product_name' => 'X', 'variant' => 'v', 'height' => 170]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['quality'] ?? null) === 'high'         // image_quality from the bag
                && ($body['aspect_ratio'] ?? null) === '3:4'      // aspect_ratio from the bag
                && ($body['seed'] ?? null) === 1234;              // determinism from params, not a literal
        });
    }

    public function test_a_fal_model_routes_to_the_fal_queue_with_both_images(): void
    {
        config()->set('services.fal.api_key', 'fal-test-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);

        // The admin points try-on at a fal EDIT model (catalogued with its provider + flat price).
        AiModel::create([
            'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'provider' => AiModel::PROVIDER_FAL,
            'model_id' => 'fal-ai/nano-banana/edit',
            'label' => 'Nano Banana Edit',
            'cost_hint_micro_usd' => 40_000,
            'cost_unit' => AiModel::UNIT_PER_IMAGE,
            'is_active' => true,
        ]);
        AiOperation::query()->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->update(['default_model' => 'fal-ai/nano-banana/edit', 'fallback_model' => null]);

        $submit = 'https://queue.fal.run/fal-ai/nano-banana/edit';
        Http::fake([
            $submit.'/requests/req-t1/status' => Http::response(['status' => 'COMPLETED'], 200),
            $submit.'/requests/req-t1/response' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/tryon.png', 'content_type' => 'image/png']]], 200),
            $submit => Http::response(['request_id' => 'req-t1'], 200),
            '*' => Http::response(self::PNG_BYTES, 200), // result download + variant-image fetch (data-URI inlining)
        ]);

        [$shopper, $variant] = $this->images();
        $result = $this->caller()->generate($this->bag(), $shopper, $variant, ['product_name' => 'X', 'variant' => 'v', 'height' => 170]);

        $this->assertSame(self::PNG_BYTES, $result->imageBytes);
        // fal is flat-rate: the catalogued per-image price is the authoritative cost.
        $this->assertTrue($result->cost->available);
        $this->assertSame(0.04, $result->cost->costUsd);

        // BOTH input images ride along as data URIs (shopper + product variant).
        Http::assertSent(function ($request) use ($submit): bool {
            if ($request->url() !== $submit) {
                return false;
            }
            $urls = $request->data()['image_urls'] ?? [];

            return count($urls) === 2
                && str_starts_with((string) $urls[0], 'data:')
                && str_starts_with((string) $urls[1], 'data:');
        });
    }

    public function test_no_image_in_response_classifies_invalid_image(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::response([
                'id' => 'gen-noimg',
                'model' => 'google/gemini-2.5-flash-image',
                'usage' => ['cost' => 0.0],
                'choices' => [['message' => ['content' => 'I cannot generate that.']]],
            ], 200),
        ]);

        [$shopper, $variant] = $this->images();

        try {
            $this->caller()->generate($this->bag(), $shopper, $variant, ['product_name' => 'X', 'variant' => 'v', 'height' => 170]);
            $this->fail('Expected invalid_image.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_INVALID_IMAGE, $e->errorCode);
        }
    }

    public function test_oversize_image_is_rejected_before_send(): void
    {
        $tooBig = str_repeat('A', ImagePayload::MAX_IMAGE_BYTES + 1);

        try {
            ImagePayload::fromBytes($tooBig, 'image/png');
            $this->fail('Expected bad_request for oversize image.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_BAD_REQUEST, $e->errorCode);
        }
    }

    public function test_unsupported_mime_is_rejected_before_send(): void
    {
        try {
            ImagePayload::fromBytes('xxx', 'image/gif');
            $this->fail('Expected bad_request for unsupported mime.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_BAD_REQUEST, $e->errorCode);
        }
    }
}
