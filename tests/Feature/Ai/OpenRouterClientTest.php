<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\OpenRouterClient;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\ParsedCost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * OpenRouterClient — the boundary. HTTP is mocked (no real paid calls). Proves
 * cost parsing (inline -> endpoint -> unavailable, never guessed), primary ->
 * fallback model selection, classified error codes, masked logging, and that the
 * bearer key never appears in a log line.
 */
class OpenRouterClientTest extends TestCase
{
    private const BASE = 'https://openrouter.ai/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.key', 'sk-or-v1-supersecretkey-should-never-leak');
        config()->set('services.openrouter.base_url', self::BASE);
        config()->set('services.openrouter.timeout', 30);

        // Fake all backoff sleeps: no real time passes, so the retry/cost-lookup
        // paths are instant and DETERMINISTIC regardless of test ordering
        // (TS-OPENROUTER-003). Auto-reset between tests by the framework.
        Sleep::fake();
    }

    private function client(): OpenRouterClient
    {
        return app(OpenRouterClient::class);
    }

    private function chatBody(string $model): array
    {
        return ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'hi']]];
    }

    public function test_check_model_ok_when_id_is_in_the_catalog(): void
    {
        Http::fake([self::BASE.'/models' => Http::response(['data' => [['id' => 'google/gemini-3.1-flash-image'], ['id' => 'google/gemini-2.5-flash']]], 200)]);

        $result = $this->client()->checkModel('google/gemini-3.1-flash-image');

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['reason']);
    }

    public function test_check_model_not_found_when_id_absent_from_catalog(): void
    {
        Http::fake([self::BASE.'/models' => Http::response(['data' => [['id' => 'google/gemini-2.5-flash']]], 200)]);

        $result = $this->client()->checkModel('openai/gpt-image-1');

        $this->assertFalse($result['ok']);
        $this->assertSame('model_not_found', $result['reason']);
    }

    public function test_parses_inline_cost_from_usage(): void
    {
        $response = [
            'id' => 'gen-1',
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'cost' => 0.0123],
            'choices' => [['message' => ['content' => '{}']]],
        ];

        $cost = $this->client()->parseCost($response);

        $this->assertTrue($cost->available);
        $this->assertSame(ParsedCost::SOURCE_INLINE, $cost->source);
        $this->assertSame(0.0123, $cost->costUsd);
    }

    public function test_missing_inline_cost_falls_back_to_generation_endpoint(): void
    {
        Http::fake([
            self::BASE.'/generation*' => Http::response(['data' => ['total_cost' => 0.05]], 200),
        ]);

        $response = ['id' => 'gen-2', 'usage' => ['prompt_tokens' => 1], 'choices' => []];

        $cost = $this->client()->parseCost($response);

        $this->assertTrue($cost->available);
        $this->assertSame(ParsedCost::SOURCE_ENDPOINT, $cost->source);
        $this->assertSame(0.05, $cost->costUsd);
    }

    public function test_cost_unavailable_when_inline_and_endpoint_both_empty(): void
    {
        // The endpoint never has a cost (lags forever in this test).
        Http::fake([
            self::BASE.'/generation*' => Http::response(['data' => []], 200),
        ]);

        $response = ['id' => 'gen-3', 'usage' => [], 'choices' => []];

        $cost = $this->client()->parseCost($response, estimatedCostMicroUsd: 40_000);

        $this->assertFalse($cost->available);
        $this->assertNull($cost->costUsd);                      // never guessed
        $this->assertSame(ParsedCost::SOURCE_UNAVAILABLE, $cost->source);
        $this->assertSame(40_000, $cost->estimatedCostMicroUsd);
    }

    public function test_primary_5xx_falls_back_to_fallback_model(): void
    {
        $calls = [];

        Http::fake(function ($request) use (&$calls) {
            $payload = $request->data();
            $calls[] = $payload['model'];

            if ($payload['model'] === 'primary/model') {
                return Http::response(['error' => ['message' => 'upstream error']], 503);
            }

            return Http::response([
                'id' => 'gen-fb',
                'model' => 'fallback/model',
                'usage' => ['cost' => 0.01],
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200);
        });

        $response = $this->client()->callWithFallback(
            'try_on_generation',
            'primary/model',
            'fallback/model',
            fn (string $model) => $this->chatBody($model),
        );

        $this->assertSame('fallback/model', $response['model']);
        // Primary was retried (bounded) then the fallback was tried.
        $this->assertContains('primary/model', $calls);
        $this->assertContains('fallback/model', $calls);
    }

    public function test_transient_retry_backoff_is_exercised_via_fakeable_sleep(): void
    {
        // Primary 503 -> one bounded retry (MAX_RETRIES=1) before moving on. The
        // retry's backoff must go through Sleep (not raw usleep) so it is fakeable
        // and the money-path suite is deterministic.
        Http::fake(function ($request) {
            return $request->data()['model'] === 'primary/model'
                ? Http::response(['error' => ['message' => 'upstream error']], 503)
                : Http::response([
                    'id' => 'gen-fb', 'model' => 'fallback/model',
                    'usage' => ['cost' => 0.01], 'choices' => [['message' => ['content' => 'ok']]],
                ], 200);
        });

        $this->client()->callWithFallback(
            'try_on_generation',
            'primary/model',
            'fallback/model',
            fn (string $model) => $this->chatBody($model),
        );

        // Exactly one backoff sleep (the single bounded retry on the primary).
        Sleep::assertSleptTimes(1);
    }

    public function test_cost_endpoint_lag_retries_sleep_between_lookups(): void
    {
        // The endpoint lags: first two lookups have no cost, the third does. The
        // gaps between lookups go through Sleep (fakeable) — proving the lag-retry
        // loop is fakeable, not a real-time flake.
        Http::fake([
            self::BASE.'/generation*' => Http::sequence()
                ->push(['data' => []], 200)
                ->push(['data' => []], 200)
                ->push(['data' => ['total_cost' => 0.07]], 200),
        ]);

        $cost = $this->client()->parseCost(['id' => 'gen-lag', 'usage' => [], 'choices' => []]);

        $this->assertTrue($cost->available);
        $this->assertSame(0.07, $cost->costUsd);
        // Two inter-lookup backoffs before the third lookup succeeded.
        Sleep::assertSleptTimes(2);
    }

    public function test_rate_limited_classified_as_rate_limited_after_fallback_exhausted(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::response(['error' => ['message' => 'rate limit']], 429),
        ]);

        try {
            $this->client()->callWithFallback(
                'product_scan',
                'primary/model',
                'fallback/model',
                fn (string $model) => $this->chatBody($model),
            );
            $this->fail('Expected OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_RATE_LIMITED, $e->errorCode);
        }
    }

    public function test_server_error_classified_as_provider_outage(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->expectException(OpenRouterException::class);

        try {
            $this->client()->chat($this->chatBody('m'), 'product_scan');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_PROVIDER_OUTAGE, $e->errorCode);
            throw $e;
        }
    }

    public function test_content_moderation_classified_as_model_refused(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::response(['error' => ['message' => 'flagged by moderation policy']], 400),
        ]);

        try {
            $this->client()->chat($this->chatBody('m'), 'try_on_generation');
            $this->fail('Expected OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_MODEL_REFUSED, $e->errorCode);
        }
    }

    public function test_bad_request_is_not_retried_on_fallback(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['message' => 'invalid parameter foo']], 400);
        });

        try {
            $this->client()->callWithFallback(
                'product_scan',
                'primary/model',
                'fallback/model',
                fn (string $model) => $this->chatBody($model),
            );
            $this->fail('Expected OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_BAD_REQUEST, $e->errorCode);
            // bad_request is our bug: it must NOT burn a fallback call.
            $this->assertSame(1, $calls);
        }
    }

    public function test_response_model_field_reports_actual_model_used(): void
    {
        $response = ['model' => 'fallback/model'];

        $this->assertSame('fallback/model', $this->client()->extractModelUsed($response, 'primary/model'));
        $this->assertSame('primary/model', $this->client()->extractModelUsed([], 'primary/model'));
    }

    public function test_bearer_key_is_never_written_to_a_log_line(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        Http::fake([
            self::BASE.'/chat/completions' => Http::response([
                'id' => 'gen-x',
                'model' => 'm',
                'usage' => ['cost' => 0.01],
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);

        $this->client()->chat($this->chatBody('m'), 'product_scan');

        $this->assertNotEmpty($logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('supersecretkey', $line, 'the bearer key leaked into a log line');
            $this->assertStringNotContainsString('sk-or-v1-supersecretkey', $line);
        }
    }

    public function test_authorization_header_carries_the_bearer_key(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'm', 'usage' => ['cost' => 0.0], 'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);

        $this->client()->chat($this->chatBody('m'), 'product_scan');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer sk-or-v1-supersecretkey-should-never-leak')
                && $request->hasHeader('HTTP-Referer')
                && $request->hasHeader('X-Title');
        });
    }
}
