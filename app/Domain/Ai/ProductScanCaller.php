<?php

namespace App\Domain\Ai;

/**
 * ProductScanCaller — operation A: product_scan extraction to strict JSON.
 *
 * pdp-scanner hands a page representation (cleaned HTML and/or a screenshot) + the
 * extraction instruction. We call a vision-capable model with structured outputs
 * enforced, run ONE repair pass if the model returns prose, and return strict
 * schema-valid JSON — never a coerced blob. We run only the model call: no
 * fetching, no rendering, no confidence heuristics.
 */
final class ProductScanCaller
{
    // === CONSTANTS ===
    private const SCHEMA_NAME = 'product_scan';
    private const REPAIR_INSTRUCTION = 'Return ONLY a single JSON object matching the schema. No prose, no markdown fences, no commentary.';
    private const DEFAULT_MAX_TOKENS = 4096;

    public function __construct(
        private readonly OpenRouterClient $client,
    ) {}

    /**
     * Extract structured product JSON.
     *
     * @param  array<string,string|int|float|null>  $vars  prompt placeholders
     * @param  array<int,ImagePayload>  $images  representation images (screenshot, etc.)
     * @param  string|null  $pageText  cleaned HTML / text representation appended to the user prompt
     */
    public function extract(
        OperationConfig $config,
        array $vars = [],
        array $images = [],
        ?string $pageText = null,
    ): ScanResult {
        $schema = $config->inputSchema;

        $response = $this->client->callWithFallback(
            $config->operationKey,
            $config->model,
            $config->fallbackModel,
            fn (string $model): array => $this->buildBody($config, $model, $vars, $images, $pageText, $schema, false),
        );

        $modelUsed = $this->client->extractModelUsed($response, $config->model);
        $content = $this->extractTextContent($response);
        $json = $this->decodeJson($content);
        $repaired = false;

        // Repair pass — exactly once — if the model returned prose / invalid JSON.
        if ($json === null) {
            $response = $this->client->callWithFallback(
                $config->operationKey,
                $config->model,
                $config->fallbackModel,
                fn (string $model): array => $this->buildBody($config, $model, $vars, $images, $pageText, $schema, true),
            );

            $modelUsed = $this->client->extractModelUsed($response, $config->model);
            $json = $this->decodeJson($this->extractTextContent($response));
            $repaired = true;
        }

        if ($json === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_JSON,
                'product_scan returned non-schema output after the repair pass; not persisting a coerced blob.',
                modelUsed: $modelUsed,
            );
        }

        return new ScanResult(
            json: $json,
            cost: $this->client->parseCost($response, $config->estimatedCostMicroUsd),
            modelUsed: $modelUsed,
            openrouterGenerationId: $this->client->extractGenerationId($response),
            repaired: $repaired,
        );
    }

    /**
     * Build the chat body. Enforces response_format json_schema when a schema is
     * present; the repair pass appends a terse JSON-only instruction.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @param  array<int,ImagePayload>  $images
     * @param  array<string,mixed>|null  $schema
     * @return array<string,mixed>
     */
    private function buildBody(
        OperationConfig $config,
        string $model,
        array $vars,
        array $images,
        ?string $pageText,
        ?array $schema,
        bool $repair,
    ): array {
        // strtr substitution — NEVER Blade::render (RCE prevention).
        $userText = $config->substituteUser($vars);

        if ($pageText !== null) {
            $userText .= "\n\n".$pageText;
        }

        if ($repair) {
            $userText .= "\n\n".self::REPAIR_INSTRUCTION;
        }

        $userContent = [['type' => 'text', 'text' => $userText]];

        foreach ($images as $image) {
            $userContent[] = $image->toContentPart();
        }

        $messages = [];
        $system = $config->substituteSystem($vars);

        if ($system !== null) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $config->params['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
        ];

        // All sampler knobs come from the bag (determinism is config, not code).
        $body = $this->applyParams($body, $config->params);

        if ($schema !== null) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => self::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ];
        }

        return $body;
    }

    /**
     * Merge the operation params bag onto the body (seed/temperature/top_p/...).
     * max_tokens is set explicitly above; the rest pass through verbatim.
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function applyParams(array $body, array $params): array
    {
        foreach (['seed', 'temperature', 'top_p'] as $knob) {
            if (array_key_exists($knob, $params)) {
                $body[$knob] = $params[$knob];
            }
        }

        return $body;
    }

    /** The assistant text content; may be a string or an array of parts. */
    private function extractTextContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        // Multimodal array form: concatenate text parts.
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
     * Decode model output to a JSON object, tolerating a ```json fence the model
     * may add. Returns null when it is not a valid JSON object (drives the repair
     * pass / invalid_json) — never coerces.
     *
     * @return array<string,mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        $trimmed = trim($content);

        // Strip a leading/trailing markdown fence if the model wrapped the JSON.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
