<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\StoryboardTextCaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The resilient storyboard text caller: recovers JSON from plain output, markdown fences, and
 * prose-wrapped output (the shapes creative models emit), and on total failure throws an error
 * carrying the RAW model output so the admin can see what went wrong (the genre-step failure).
 */
class StoryboardTextCallerTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        Sleep::fake();
    }

    private function config(): OperationConfig
    {
        return new OperationConfig(
            operationKey: 'storyboard_genre',
            model: 'google/gemini-2.5-flash',
            fallbackModel: null,
            systemPrompt: 'You define the genre.',
            userPrompt: 'Return the genre profile.',
            imageQuality: null,
            aspectRatio: null,
            params: ['temperature' => 0.6],
            creditMultiplier: null,
            promptVersion: 1,
            estimatedCostMicroUsd: 4_000,
            inputSchema: ['type' => 'object', 'properties' => ['genre' => ['type' => 'string']]],
        );
    }

    private function fakeContent(string $content): void
    {
        Http::fake([self::CHAT => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.001],
        ], 200)]);
    }

    public function test_it_parses_plain_json(): void
    {
        $this->fakeContent('{"genre":"comedy","emotional_tone":"fun"}');

        $result = app(StoryboardTextCaller::class)->extract($this->config());

        $this->assertSame('comedy', $result->json['genre']);
        $this->assertTrue($result->cost->available);
    }

    public function test_it_parses_json_inside_a_markdown_fence(): void
    {
        $this->fakeContent("```json\n{\"genre\":\"trailer\"}\n```");

        $result = app(StoryboardTextCaller::class)->extract($this->config());

        $this->assertSame('trailer', $result->json['genre']);
    }

    public function test_it_recovers_json_wrapped_in_prose(): void
    {
        $this->fakeContent("Sure! Here is the genre profile:\n\n{\"genre\":\"drama\",\"emotional_tone\":\"tense\"}\n\nLet me know if you want changes.");

        $result = app(StoryboardTextCaller::class)->extract($this->config());

        $this->assertSame('drama', $result->json['genre']);
    }

    public function test_it_recovers_json_with_unescaped_newlines_inside_strings(): void
    {
        // gemini writing a multi-line value emits a REAL newline inside the string — invalid JSON
        // that json_decode rejects; the sanitizer escapes it. This is the Visual-bible failure.
        $this->fakeContent("{\n  \"global_style\": \"A classic hero's journey\nwith clear lighting\",\n  \"negative_prompt\": \"no cartoon\"\n}");

        $result = app(StoryboardTextCaller::class)->extract($this->config());

        $this->assertStringContainsString('hero', $result->json['global_style']);
        $this->assertStringContainsString("\n", $result->json['global_style']); // the newline is preserved
    }

    public function test_it_throws_with_the_raw_output_when_no_json_is_recoverable(): void
    {
        $this->fakeContent('I cannot produce that output.');

        try {
            app(StoryboardTextCaller::class)->extract($this->config());
            $this->fail('expected an invalid-json exception');
        } catch (OpenRouterException $e) {
            $this->assertStringContainsString('I cannot produce that output', $e->getMessage());
        }

        // Initial attempt + 2 repairs = 3 calls.
        Http::assertSentCount(3);
    }
}
