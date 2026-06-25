<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\ProductScanCaller;
use App\Models\AiOperation;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * product_scan extraction. HTTP mocked. Proves strict JSON returns clean, a prose
 * response triggers exactly ONE repair pass, and a still-bad response classifies
 * as invalid_json (never a coerced persist).
 */
class ProductScanCallerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://openrouter.ai/api/v1';

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

    private function caller(): ProductScanCaller
    {
        return app(ProductScanCaller::class);
    }

    private function bag()
    {
        return (new AiOperationResolver)->for(AiOperation::KEY_PRODUCT_SCAN);
    }

    private function chatResponse(string $content, string $id = 'gen-scan'): array
    {
        return [
            'id' => $id,
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.0021],
            'choices' => [['message' => ['content' => $content]]],
        ];
    }

    public function test_returns_strict_json_on_clean_response(): void
    {
        $json = json_encode([
            'product_name' => 'Red Sneaker',
            'product_type' => 'shoes',
            'main_image' => 'https://cdn/x.jpg',
        ]);

        Http::fake([self::BASE.'/chat/completions' => Http::response($this->chatResponse($json), 200)]);

        $result = $this->caller()->extract($this->bag(), ['product_name' => 'Red Sneaker']);

        $this->assertSame('Red Sneaker', $result->json['product_name']);
        $this->assertSame('shoes', $result->json['product_type']);
        $this->assertFalse($result->repaired);
        $this->assertTrue($result->cost->available);
        $this->assertSame(0.0021, $result->cost->costUsd);
        $this->assertSame('gen-scan', $result->openrouterGenerationId);
    }

    public function test_prose_response_triggers_one_repair_pass_then_succeeds(): void
    {
        $sequence = Http::sequence()
            // First call: the model returns prose (ignored structured output).
            ->push($this->chatResponse('Sure! Here is the product: a Red Sneaker.', 'gen-prose'), 200)
            // Repair call: now valid JSON.
            ->push($this->chatResponse(json_encode([
                'product_name' => 'Red Sneaker',
                'product_type' => 'shoes',
                'main_image' => 'https://cdn/x.jpg',
            ]), 'gen-repair'), 200);

        Http::fake([self::BASE.'/chat/completions' => $sequence]);

        $result = $this->caller()->extract($this->bag(), ['product_name' => 'Red Sneaker']);

        $this->assertTrue($result->repaired);
        $this->assertSame('Red Sneaker', $result->json['product_name']);
        $this->assertSame('gen-repair', $result->openrouterGenerationId);
    }

    public function test_json_in_markdown_fence_is_decoded(): void
    {
        $fenced = "```json\n".json_encode([
            'product_name' => 'Fenced',
            'product_type' => 'bag',
            'main_image' => 'https://cdn/y.jpg',
        ])."\n```";

        Http::fake([self::BASE.'/chat/completions' => Http::response($this->chatResponse($fenced), 200)]);

        $result = $this->caller()->extract($this->bag());

        $this->assertSame('Fenced', $result->json['product_name']);
        $this->assertFalse($result->repaired);
    }

    public function test_still_invalid_after_repair_classifies_invalid_json(): void
    {
        // Both the first call AND the repair return prose.
        Http::fake([
            self::BASE.'/chat/completions' => Http::response($this->chatResponse('Not JSON at all, sorry.'), 200),
        ]);

        try {
            $this->caller()->extract($this->bag());
            $this->fail('Expected invalid_json.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_INVALID_JSON, $e->errorCode);
        }
    }

    public function test_request_carries_response_format_json_schema_strict(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::response($this->chatResponse(json_encode([
                'product_name' => 'X', 'product_type' => 'y', 'main_image' => 'z',
            ])), 200),
        ]);

        $this->caller()->extract($this->bag());

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['response_format']['type'] ?? null) === 'json_schema'
                && ($body['response_format']['json_schema']['strict'] ?? null) === true
                && ($body['response_format']['json_schema']['name'] ?? null) === 'product_scan';
        });
    }
}
