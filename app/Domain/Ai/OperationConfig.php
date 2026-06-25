<?php

namespace App\Domain\Ai;

/**
 * OperationConfig — the immutable resolved AI-config bag.
 *
 * The ONE thing AiOperationResolver::for() returns and the ONLY source of a
 * model / prompt / quality / aspect ratio / seed in the whole codebase. A caller
 * uses what this returns — full stop. No service hardcodes any of these.
 *
 * The raw user_prompt / system_prompt are templates carrying {{placeholders}};
 * the caller substitutes them with strtr() at call time (see substituteUser()),
 * NEVER Blade::render() (RCE prevention).
 */
final readonly class OperationConfig
{
    /**
     * @param  array<string,mixed>  $params  sampler bag: seed, temperature, top_p, max_tokens, ...
     * @param  array<string,mixed>|null  $inputSchema  strict JSON schema (product_scan)
     */
    public function __construct(
        public string $operationKey,
        public string $model,
        public ?string $fallbackModel,
        public ?string $systemPrompt,
        public string $userPrompt,
        public ?string $imageQuality,
        public ?string $aspectRatio,
        public array $params,
        public ?float $creditMultiplier,
        public int $promptVersion,
        public ?int $estimatedCostMicroUsd,
        public ?array $inputSchema,
    ) {}

    /**
     * Substitute {{placeholders}} in the user prompt with strtr() — never Blade.
     * Keys are wrapped in {{ }} so a caller passes plain names ('product_name').
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    public function substituteUser(array $vars): string
    {
        return $this->substitute($this->userPrompt, $vars);
    }

    /**
     * Substitute {{placeholders}} in the system prompt with strtr() — never Blade.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    public function substituteSystem(array $vars): ?string
    {
        if ($this->systemPrompt === null) {
            return null;
        }

        return $this->substitute($this->systemPrompt, $vars);
    }

    /**
     * The single, sanctioned templating path. Merchant/admin-edited prompt text
     * is DATA, not code: strtr does a literal string swap and never evaluates
     * its inputs, so a placeholder value containing Blade/PHP ({{ 7*7 }}, @php)
     * is rendered literally. This is the RCE-prevention guarantee.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    private function substitute(string $template, array $vars): string
    {
        $map = [];

        foreach ($vars as $key => $value) {
            $map['{{'.$key.'}}'] = (string) ($value ?? '');
        }

        return strtr($template, $map);
    }
}
