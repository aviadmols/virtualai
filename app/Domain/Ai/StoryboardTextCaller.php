<?php

namespace App\Domain\Ai;

/**
 * StoryboardTextCaller — a RESILIENT text→JSON caller for the storyboard pipeline steps.
 *
 * The creative steps (genre, characters, visual bible, scene breakdown) return JSON far less
 * predictably than a product scan, so this does NOT reuse the strict scan path. It passes the
 * schema as GUIDANCE (json_schema strict:false, or json_object when there is no schema), extracts
 * the JSON robustly (tolerating markdown fences and prose-wrapped output), retries with a JSON-only
 * nudge, and — critically — puts the RAW model output in the error so a failure is diagnosable.
 * Returns a ScanResult (json + cost + model used) so the pipeline reads it uniformly.
 */
final class StoryboardTextCaller
{
    // === CONSTANTS ===
    private const SCHEMA_NAME = 'storyboard_step';
    private const MAX_REPAIRS = 2;
    private const DEFAULT_MAX_TOKENS = 4096;
    private const RAW_SNIPPET = 600;
    private const JSON_ONLY = 'Return ONLY a single valid JSON object with the described fields. No prose, no explanation, no markdown code fences — just the JSON object itself.';

    public function __construct(
        private readonly OpenRouterClient $client,
    ) {}

    /**
     * Run the step and return its JSON. Retries a JSON-only repair up to MAX_REPAIRS; throws a
     * CODE_INVALID_JSON carrying the raw output if it never parses.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    public function extract(OperationConfig $config, array $vars = []): ScanResult
    {
        $lastRaw = '';
        $repaired = false;

        for ($attempt = 0; $attempt <= self::MAX_REPAIRS; $attempt++) {
            $response = $this->client->callWithFallback(
                $config->operationKey,
                $config->model,
                $config->fallbackModel,
                fn (string $model): array => $this->buildBody($config, $model, $vars, $attempt > 0),
            );

            $lastRaw = $this->extractText($response);
            $json = $this->robustDecode($lastRaw);

            if ($json !== null) {
                return new ScanResult(
                    json: $json,
                    cost: $this->client->parseCost($response, $config->estimatedCostMicroUsd),
                    modelUsed: $this->client->extractModelUsed($response, $config->model),
                    openrouterGenerationId: $this->client->extractGenerationId($response),
                    repaired: $repaired,
                );
            }

            $repaired = true;
        }

        throw OpenRouterException::make(
            OpenRouterException::CODE_INVALID_JSON,
            sprintf(
                '%s did not return valid JSON after %d attempts. Model output: %s',
                $config->operationKey,
                self::MAX_REPAIRS + 1,
                mb_substr(trim($lastRaw), 0, self::RAW_SNIPPET) ?: '(empty response)',
            ),
        );
    }

    /**
     * The chat body. Schema is passed as GUIDANCE (strict:false) so a creative model isn't forced
     * into an all-or-nothing strict decode; json_object mode when there is no schema.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildBody(OperationConfig $config, string $model, array $vars, bool $repair): array
    {
        // strtr substitution — never Blade (RCE prevention).
        $userText = $config->substituteUser($vars);

        if ($repair) {
            $userText .= "\n\n".self::JSON_ONLY;
        }

        $messages = [];
        $system = $config->substituteSystem($vars);
        if ($system !== null) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $userText];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $config->params['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
        ];

        foreach (['temperature', 'top_p', 'seed'] as $knob) {
            if (array_key_exists($knob, $config->params)) {
                $body[$knob] = $config->params[$knob];
            }
        }

        $body['response_format'] = $config->inputSchema !== null
            ? ['type' => 'json_schema', 'json_schema' => ['name' => self::SCHEMA_NAME, 'strict' => false, 'schema' => $config->inputSchema]]
            : ['type' => 'json_object'];

        return $body;
    }

    /** The assistant text content (string or multimodal parts). */
    private function extractText(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }

            return $text;
        }

        return '';
    }

    /**
     * Decode to a JSON object, tolerating: markdown fences, and JSON embedded in surrounding prose
     * (extract the outermost {...}). Returns null when no JSON object can be recovered — never
     * coerces.
     *
     * @return array<string,mixed>|null
     */
    private function robustDecode(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '```')) {
            $trimmed = trim((string) preg_replace('/```[a-zA-Z]*\s*|\s*```/', '', $trimmed));
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Prose-wrapped: recover the outermost JSON object.
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
