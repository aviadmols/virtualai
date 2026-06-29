<?php

namespace App\Domain\Ai\Preview;

use App\Domain\Ai\OperationConfig;

/**
 * OperationPreview — the read-only "which prompt + which model wins, and why"
 * result for the platform prompts editor (Phase 8c).
 *
 * Built by AiOperationResolver::preview() using the EXACT SAME resolution
 * precedence as the real pipeline (site -> account -> product_type -> global for
 * prompts; site/account override -> operation default -> catalog default for the
 * model). It runs NO OpenRouter HTTP call and writes NOTHING.
 *
 * It exposes the RAW resolved template text (winningUserPrompt / winningSystemPrompt)
 * plus the resolution traces. Actual sample-variable preview rendering is the UI
 * agent's job; this class offers a SAFE strtr-based helper (renderUserPrompt /
 * renderSystemPrompt) that is identical to OperationConfig's substitution — never
 * Blade::render() (RCE prevention, CLAUDE.md). The helper does NOT html-escape:
 * escaping belongs at the UI render boundary (htmlspecialchars), so this value
 * object stays presentation-agnostic and returns plain substituted text.
 */
final readonly class OperationPreview
{
    /**
     * @param  list<string>  $modelChain  the models that would actually be tried, in order: [winning model, fallback?]
     * @param  array<string,mixed>  $params  the sampler bag (seed/temperature/...) — determinism is config, shown not hidden
     */
    public function __construct(
        public string $operationKey,
        public ?string $productType,
        public ?int $siteId,
        public ?int $accountId,
        // --- model ---
        public string $winningModel,
        public ?string $fallbackModel,
        public array $modelChain,
        public ResolutionTrace $modelTrace,
        // --- prompt ---
        public int|string|null $winningPromptId,
        public string $winningPromptLevel,
        public int $winningPromptVersion,
        public ?string $winningSystemPrompt,
        public string $winningUserPrompt,
        public ResolutionTrace $promptTrace,
        // --- the rest of the resolved bag (shown read-only) ---
        public ?string $imageQuality,
        public ?string $aspectRatio,
        public array $params,
        public ?float $creditMultiplier,
    ) {}

    /**
     * Build the preview from the resolved OperationConfig plus the traces.
     * Keeps the winning values in lock-step with what the real resolver returned.
     */
    public static function fromConfig(
        OperationConfig $config,
        ?int $siteId,
        ?int $accountId,
        ?string $productType,
        int|string|null $winningPromptId,
        string $winningPromptLevel,
        ResolutionTrace $modelTrace,
        ResolutionTrace $promptTrace,
    ): self {
        $chain = array_values(array_filter(
            [$config->model, $config->fallbackModel],
            static fn (?string $m): bool => $m !== null && $m !== '',
        ));

        return new self(
            operationKey: $config->operationKey,
            productType: $productType,
            siteId: $siteId,
            accountId: $accountId,
            winningModel: $config->model,
            fallbackModel: $config->fallbackModel,
            modelChain: $chain,
            modelTrace: $modelTrace,
            winningPromptId: $winningPromptId,
            winningPromptLevel: $winningPromptLevel,
            winningPromptVersion: $config->promptVersion,
            winningSystemPrompt: $config->systemPrompt,
            winningUserPrompt: $config->userPrompt,
            promptTrace: $promptTrace,
            imageQuality: $config->imageQuality,
            aspectRatio: $config->aspectRatio,
            params: $config->params,
            creditMultiplier: $config->creditMultiplier,
        );
    }

    /**
     * SAFE sample-substitution of the user prompt via strtr (NEVER Blade).
     * Returns plain text; the UI escapes at its render boundary.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    public function renderUserPrompt(array $vars): string
    {
        return $this->substitute($this->winningUserPrompt, $vars);
    }

    /**
     * SAFE sample-substitution of the system prompt via strtr (NEVER Blade).
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    public function renderSystemPrompt(array $vars): ?string
    {
        if ($this->winningSystemPrompt === null) {
            return null;
        }

        return $this->substitute($this->winningSystemPrompt, $vars);
    }

    /**
     * The single sanctioned templating path — identical to OperationConfig's:
     * strtr does a literal swap and never evaluates its inputs, so a value
     * containing Blade/PHP ({{ 7*7 }}, @php) renders literally. RCE-safe.
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

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'operation_key' => $this->operationKey,
            'product_type' => $this->productType,
            'site_id' => $this->siteId,
            'account_id' => $this->accountId,
            'model' => [
                'winning' => $this->winningModel,
                'fallback' => $this->fallbackModel,
                'chain' => $this->modelChain,
                'trace' => $this->modelTrace->toArray(),
            ],
            'prompt' => [
                'id' => $this->winningPromptId,
                'level' => $this->winningPromptLevel,
                'version' => $this->winningPromptVersion,
                'system_prompt' => $this->winningSystemPrompt,
                'user_prompt' => $this->winningUserPrompt,
                'trace' => $this->promptTrace->toArray(),
            ],
            'image_quality' => $this->imageQuality,
            'aspect_ratio' => $this->aspectRatio,
            'params' => $this->params,
            'credit_multiplier' => $this->creditMultiplier,
        ];
    }
}
