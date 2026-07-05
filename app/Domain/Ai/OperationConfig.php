<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;

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
        // Which upstream serves the model (openrouter|byteplus). Defaults to OpenRouter so
        // every existing caller/construction stays valid; the resolver sets the real value.
        public string $provider = ImageGenerationProvider::PROVIDER_OPENROUTER,
        // Which upstream serves the FALLBACK model. The fallback may live on a different
        // provider than the primary (e.g. a BytePlus default with a Gemini/OpenRouter
        // fallback), so cross-provider fallback routes it correctly. Defaults to OpenRouter.
        public string $fallbackProvider = ImageGenerationProvider::PROVIDER_OPENROUTER,
        // The resolved PER-MODEL price hint (micro-USD) for the primary/fallback model,
        // from ai_models.cost_hint_micro_usd. For a FLAT-RATE provider (BytePlus returns
        // no inline USD cost) this is the authoritative per-image charge — the resolver
        // is the single source, no service literal. Null when the model has no hint; the
        // caller then falls back to the operation estimate (flatRatePriceMicroUsd()).
        public ?int $modelCostHintMicroUsd = null,
        public ?int $fallbackModelCostHintMicroUsd = null,
    ) {}

    /**
     * The authoritative flat-rate per-image price (micro-USD) to charge for the PRIMARY
     * model on a flat-rate provider: the resolved per-model cost hint, falling back to
     * the operation estimate. Returns null when neither is a positive price (so parseCost
     * fails closed to `unavailable` and the money path never invents a cost).
     */
    public function flatRatePriceMicroUsd(): ?int
    {
        return self::positivePrice($this->modelCostHintMicroUsd)
            ?? self::positivePrice($this->estimatedCostMicroUsd);
    }

    /**
     * The authoritative flat-rate per-image price for the FALLBACK model, same fallback
     * chain as the primary (fallback model hint -> operation estimate). Null when no
     * positive price is configured.
     */
    public function fallbackFlatRatePriceMicroUsd(): ?int
    {
        return self::positivePrice($this->fallbackModelCostHintMicroUsd)
            ?? self::positivePrice($this->estimatedCostMicroUsd);
    }

    /**
     * True when this config CANNOT produce an honest charge and it is knowable BEFORE
     * the provider call — i.e. EVERY usable attempt is a flat-rate provider (BytePlus,
     * which returns no inline USD cost) AND none has a positive configured price. The
     * money path fails EARLY on this (no wasted render). It returns FALSE the moment any
     * attempt is OpenRouter (that path returns a real inline cost, so cost is knowable
     * only after the call) or any flat-rate attempt has a positive price.
     */
    public function flatRatePriceMissing(): bool
    {
        if ($this->isFlatRate($this->provider) && $this->flatRatePriceMicroUsd() !== null) {
            return false; // the primary flat-rate model has a price -> it can charge
        }

        if (! $this->isFlatRate($this->provider)) {
            return false; // the primary is OpenRouter -> real inline cost, not knowable early
        }

        // The primary is a flat-rate model with NO price. Only a usable fallback that CAN
        // charge (an OpenRouter model, or a flat-rate model with a price) rescues it.
        if ($this->fallbackModel === null || $this->fallbackModel === '') {
            return true;
        }

        if (! $this->isFlatRate($this->fallbackProvider)) {
            return false; // the fallback is OpenRouter -> can charge
        }

        return $this->fallbackFlatRatePriceMicroUsd() === null;
    }

    /** A provider is flat-rate when it returns no inline USD cost (BytePlus today). */
    private function isFlatRate(string $provider): bool
    {
        return $provider === ImageGenerationProvider::PROVIDER_BYTEPLUS;
    }

    /** A price is usable only when it is a positive micro-USD amount; else null. */
    private static function positivePrice(?int $micro): ?int
    {
        return $micro !== null && $micro > 0 ? $micro : null;
    }

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
