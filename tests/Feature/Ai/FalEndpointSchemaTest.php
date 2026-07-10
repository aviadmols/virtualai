<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\FalEndpointSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FalEndpointSchema: fal's generation knobs are per-model enums read from the endpoint's public
 * OpenAPI document — inputSchema() caches the fetch and fails OPEN to []; shapeBody() clamps
 * duration/resolution/ratio to the model's allowed values (emitting enum entries VERBATIM so the
 * original int/string type survives) and degrades to the legacy prompt+images body without a
 * schema; effectiveDuration() reports the numeric seconds the clamp would send.
 */
class FalEndpointSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'alibaba/happy-horse/v1.1/reference-to-video';
    private const OPENAPI = 'https://fal.ai/api/openapi/queue/openapi.json*';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);
    }

    private function service(): FalEndpointSchema
    {
        return app(FalEndpointSchema::class);
    }

    /** @return array<string,mixed> */
    private function doc(array $properties): array
    {
        return [
            'paths' => ['/' => ['post' => ['requestBody' => ['content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/HorseInput'],
            ]]]]]],
            'components' => ['schemas' => ['HorseInput' => [
                'type' => 'object',
                'properties' => array_merge(['prompt' => ['type' => 'string']], $properties),
            ]]],
        ];
    }

    /** @return array<string,mixed> */
    private function schema(array $properties): array
    {
        return ['properties' => array_merge(['prompt' => ['type' => 'string']], $properties)];
    }

    public function test_the_input_schema_is_fetched_once_and_cached(): void
    {
        Http::fake([self::OPENAPI => Http::response($this->doc(['duration' => ['type' => 'integer', 'enum' => [3, 5]]]), 200)]);

        $first = $this->service()->inputSchema(self::MODEL);
        $second = $this->service()->inputSchema(self::MODEL);

        $this->assertArrayHasKey('duration', $first['properties']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_an_unreachable_or_garbage_document_fails_open_to_an_empty_schema(): void
    {
        Http::fake([self::OPENAPI => Http::response(['not' => 'openapi'], 500)]);

        $this->assertSame([], $this->service()->inputSchema(self::MODEL));
        // The empty result is cached (short TTL) — no second fetch.
        $this->assertSame([], $this->service()->inputSchema(self::MODEL));
        Http::assertSentCount(1);
    }

    public function test_a_document_without_a_recognizable_input_schema_yields_empty(): void
    {
        Http::fake([self::OPENAPI => Http::response(['components' => ['schemas' => ['Output' => ['properties' => ['video' => []]]]]], 200)]);

        $this->assertSame([], $this->service()->inputSchema(self::MODEL));
    }

    public function test_an_integer_duration_enum_clamps_both_ways_and_stays_an_int(): void
    {
        $schema = $this->schema(['duration' => ['type' => 'integer', 'enum' => [3, 5, 10, 15]]]);

        $high = $this->service()->shapeBody($schema, 'p', [], ['duration_seconds' => 120]);
        $low = $this->service()->shapeBody($schema, 'p', [], ['duration_seconds' => 2]);

        $this->assertSame(15, $high['duration']);
        $this->assertSame(3, $low['duration']);
        $this->assertSame(15, $this->service()->effectiveDuration($schema, 120));
    }

    public function test_a_string_duration_enum_emits_the_enum_value_verbatim(): void
    {
        $schema = $this->schema(['duration' => ['type' => 'string', 'enum' => ['5', '10']]]);

        $this->assertSame('5', $this->service()->shapeBody($schema, 'p', [], ['duration_seconds' => 8])['duration']);
        $this->assertSame('10', $this->service()->shapeBody($schema, 'p', [], ['duration_seconds' => 12])['duration']);
        $this->assertSame(10, $this->service()->effectiveDuration($schema, 12));
    }

    public function test_a_const_duration_like_veo_8s_is_sent_verbatim(): void
    {
        $schema = $this->schema(['duration' => ['type' => 'string', 'const' => '8s']]);

        $this->assertSame('8s', $this->service()->shapeBody($schema, 'p', [], ['duration_seconds' => 15])['duration']);
        $this->assertSame(8, $this->service()->effectiveDuration($schema, 15));
    }

    public function test_effective_duration_is_null_without_a_duration_knob(): void
    {
        $this->assertNull($this->service()->effectiveDuration($this->schema([]), 15));
        $this->assertNull($this->service()->effectiveDuration([], 15));
    }

    public function test_the_resolution_snaps_to_the_nearest_allowed_value(): void
    {
        $schema = $this->schema(['resolution' => ['type' => 'string', 'enum' => ['720p', '1080p']]]);

        // 480p is not offered — the nearest allowed wins (never a 422, never a silent 1080p upsell).
        $this->assertSame('720p', $this->service()->shapeBody($schema, 'p', [], ['resolution' => '480p'])['resolution']);
        $this->assertSame('1080p', $this->service()->shapeBody($schema, 'p', [], ['resolution' => '1080p'])['resolution']);
    }

    public function test_the_aspect_ratio_is_sent_only_on_an_exact_enum_match(): void
    {
        $schema = $this->schema(['aspect_ratio' => ['type' => 'string', 'enum' => ['16:9', '9:16']]]);

        $exact = $this->service()->shapeBody($schema, 'p', [], ['ratio' => '16:9']);
        $adaptive = $this->service()->shapeBody($schema, 'p', [], ['ratio' => 'adaptive']);

        $this->assertSame('16:9', $exact['aspect_ratio']);
        $this->assertArrayNotHasKey('aspect_ratio', $adaptive);
    }

    public function test_the_prompt_is_truncated_to_the_schemas_max_length(): void
    {
        $schema = $this->schema(['prompt' => ['type' => 'string', 'maxLength' => 5]]);

        $this->assertSame('abcde', $this->service()->shapeBody($schema, 'abcdefgh', [], [])['prompt']);
    }

    public function test_images_follow_the_keys_the_schema_declares_and_the_max_items_cap(): void
    {
        $single = $this->schema(['image_url' => ['type' => 'string']]);
        $multi = $this->schema(['image_urls' => ['type' => 'array', 'maxItems' => 2]]);

        $singleBody = $this->service()->shapeBody($single, 'p', ['a', 'b', 'c'], []);
        $multiBody = $this->service()->shapeBody($multi, 'p', ['a', 'b', 'c'], []);

        $this->assertSame('a', $singleBody['image_url']);
        $this->assertArrayNotHasKey('image_urls', $singleBody);
        $this->assertSame(['a', 'b'], $multiBody['image_urls']);
        $this->assertArrayNotHasKey('image_url', $multiBody);
    }

    public function test_an_empty_schema_yields_the_legacy_body(): void
    {
        $body = $this->service()->shapeBody([], 'prompt-text', ['a', 'b'], ['duration_seconds' => 15, 'resolution' => '720p']);

        $this->assertSame(['prompt' => 'prompt-text', 'image_url' => 'a', 'image_urls' => ['a', 'b']], $body);
    }
}
