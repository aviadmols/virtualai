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
    private const RAW_SNIPPET = 1500;
    private const CTRL_CHAR_MAX = 0x20;
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
            // Params may arrive as STRINGS (the admin KeyValue editor), but the API needs numbers —
            // a string temperature is a 400. Coerce every numeric knob.
            'max_tokens' => (int) ($config->params['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];

        foreach (['temperature', 'top_p'] as $knob) {
            if (isset($config->params[$knob]) && is_numeric($config->params[$knob])) {
                $body[$knob] = (float) $config->params[$knob];
            }
        }

        if (isset($config->params['seed']) && is_numeric($config->params['seed'])) {
            $body['seed'] = (int) $config->params['seed'];
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
     * Decode to a JSON object, tolerating: markdown fences, JSON embedded in surrounding prose
     * (the outermost {...}), and UNESCAPED control characters inside string values (real newlines/
     * tabs — the #1 way a creative model emits invalid JSON). Returns null when nothing parses —
     * never coerces.
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

        // Candidate = the outermost {...} if present (strips leading/trailing prose), else the whole.
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        $candidate = ($start !== false && $end !== false && $end > $start)
            ? substr($trimmed, $start, $end - $start + 1)
            : $trimmed;

        // Try as-is, then with control chars inside strings escaped.
        foreach ([$candidate, $this->escapeControlCharsInStrings($candidate)] as $attempt) {
            $decoded = json_decode($attempt, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Escape raw control characters (newline/tab/…) that appear INSIDE string literals, which make
     * JSON invalid. Walks the bytes tracking string/escape state so structural whitespace between
     * tokens is untouched; multibyte UTF-8 (bytes >= 0x80) passes through unchanged.
     */
    private function escapeControlCharsInStrings(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if (! $inString) {
                if ($char === '"') {
                    $inString = true;
                }
                $out .= $char;

                continue;
            }

            if ($escaped) {
                $out .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $out .= $char;
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $out .= $char;
                $inString = false;

                continue;
            }

            if (ord($char) < self::CTRL_CHAR_MAX) {
                $out .= match ($char) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => sprintf('\\u%04x', ord($char)),
                };

                continue;
            }

            $out .= $char;
        }

        return $out;
    }
}
