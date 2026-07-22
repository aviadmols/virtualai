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
    // === CONSTANTS ===
    // How appended merchant art-direction joins the base user prompt: a labelled separator so the
    // model reads the note as EXTRA styling instructions, not part of the base task.
    private const NOTE_SEPARATOR = "\n\nAdditional art direction from the merchant"
        .' (apply where it does not conflict with the above): ';

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
        // The version of the platform-global rules directive woven into the system prompt for this
        // surface (0 = none). Output-deciding, so it is folded into the generation idempotency keys:
        // a Super-Admin rule edit bumps this and re-generates instead of colliding as a duplicate.
        public int $directiveVersion = 0,
    ) {}

    /**
     * A clone with the USER prompt overridden. A style preset swaps ONLY the user prompt, keeping
     * the operation's model, quality, aspect, provider, cost hints AND system prompt intact — so
     * the guardrails/system framing stay and only the "look" changes. The new prompt still
     * substitutes {{tokens}} via substituteUser (strtr, never Blade).
     */
    public function withUserPrompt(string $userPrompt): self
    {
        return $this->copyWith(userPrompt: $userPrompt);
    }

    /**
     * A clone with extra merchant art-direction APPENDED to the user prompt (e.g. "background
     * #f5f5f0", small tweaks). Empty/null is a no-op. The note is DATA: it is appended to the
     * template and substituted with strtr like the rest — never evaluated (RCE-safe). A labelled
     * separator keeps it distinct from the base instructions for the model.
     */
    public function withAppendedUserPrompt(?string $note): self
    {
        $note = trim((string) $note);

        if ($note === '') {
            return $this;
        }

        return $this->copyWith(userPrompt: $this->userPrompt.self::NOTE_SEPARATOR.$note);
    }

    /**
     * A clone with the aspect ratio overridden (e.g. the merchant picks 4:5 for a batch). Null/
     * empty keeps the operation's configured ratio — the override only ever SETS a value.
     */
    public function withAspectRatio(?string $aspectRatio): self
    {
        $aspectRatio = trim((string) $aspectRatio);

        return $aspectRatio === '' ? $this : $this->copyWith(aspectRatio: $aspectRatio);
    }

    /**
     * A clone with the image quality overridden (standard | high). Null/empty keeps the operation's
     * configured quality — the override only ever SETS a value.
     */
    public function withImageQuality(?string $imageQuality): self
    {
        $imageQuality = trim((string) $imageQuality);

        return $imageQuality === '' ? $this : $this->copyWith(imageQuality: $imageQuality);
    }

    /**
     * One immutable-clone helper: every override method funnels through here so the (long)
     * constructor is written once. Only the named fields change; everything else is carried over.
     */
    private function copyWith(
        ?string $userPrompt = null,
        ?string $imageQuality = null,
        ?string $aspectRatio = null,
    ): self {
        return new self(
            operationKey: $this->operationKey,
            model: $this->model,
            fallbackModel: $this->fallbackModel,
            systemPrompt: $this->systemPrompt,
            userPrompt: $userPrompt ?? $this->userPrompt,
            imageQuality: $imageQuality ?? $this->imageQuality,
            aspectRatio: $aspectRatio ?? $this->aspectRatio,
            params: $this->params,
            creditMultiplier: $this->creditMultiplier,
            promptVersion: $this->promptVersion,
            estimatedCostMicroUsd: $this->estimatedCostMicroUsd,
            inputSchema: $this->inputSchema,
            provider: $this->provider,
            fallbackProvider: $this->fallbackProvider,
            modelCostHintMicroUsd: $this->modelCostHintMicroUsd,
            fallbackModelCostHintMicroUsd: $this->fallbackModelCostHintMicroUsd,
            directiveVersion: $this->directiveVersion,
        );
    }

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
     * xAI, AtlasCloud, fal, Kling — none returns an inline USD cost) AND none has a
     * positive configured price. The
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

    /** A provider is flat-rate when it returns no inline USD cost (BytePlus + xAI/Grok + AtlasCloud + fal + Kling). */
    private function isFlatRate(string $provider): bool
    {
        return in_array($provider, [
            ImageGenerationProvider::PROVIDER_BYTEPLUS,
            ImageGenerationProvider::PROVIDER_XAI,
            ImageGenerationProvider::PROVIDER_ATLASCLOUD,
            ImageGenerationProvider::PROVIDER_FAL,
            ImageGenerationProvider::PROVIDER_KLING,
        ], true);
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
