<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\ParsedCost;

/**
 * ImageGenerationProvider — the provider seam for try-on image generation.
 *
 * One implementation per upstream (OpenRouter, BytePlus/Seedream, xAI/Grok). The caller
 * (TryOnGenerationCaller) speaks ONLY this interface; it never knows which upstream is
 * behind it. Fallback/retry classification stays provider-side (each upstream has its own
 * error envelope + status semantics + response shape).
 */
interface ImageGenerationProvider
{
    // Stable provider ids — also the ai_models.provider enum values.
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_BYTEPLUS = 'byteplus';
    public const PROVIDER_XAI = 'xai';
    // AtlasCloud is a VIDEO-only upstream (async task API); it never serves try-on images, but its
    // id lives here as the single canonical provider list (ai_models.provider enum, costs report).
    public const PROVIDER_ATLASCLOUD = 'atlascloud';
    // fal.ai serves BOTH images and video through its queue API (one endpoint per model).
    public const PROVIDER_FAL = 'fal';

    public const PROVIDERS = [self::PROVIDER_OPENROUTER, self::PROVIDER_BYTEPLUS, self::PROVIDER_XAI, self::PROVIDER_ATLASCLOUD, self::PROVIDER_FAL];

    /**
     * Run $buildBody($model) against the primary then the fallback model, retrying
     * transient failures. Returns the first successful decoded response.
     *
     * @param  callable(string):array<string,mixed>  $buildBody
     * @return array<string,mixed>
     */
    public function callWithFallback(
        string $operationKey,
        string $primaryModel,
        ?string $fallbackModel,
        callable $buildBody,
    ): array;

    /** The model the upstream actually used (may be the fallback). */
    public function extractModelUsed(array $response, string $requested): string;

    /** The upstream generation id, if any (cost/audit correlation) — else null. */
    public function extractGenerationId(array $response): ?string;

    /** Cost of the generation, or an honest unavailable. NEVER guesses. */
    public function parseCost(array $response, ?int $estimatedCostMicroUsd = null): ParsedCost;

    /**
     * Result image bytes + mime from the response, or [null, ''] when none is usable.
     * Provider-side because each upstream has a different response shape.
     *
     * @return array{0: string|null, 1: string}
     */
    public function extractImage(array $response): array;

    /**
     * Test connectivity WITHOUT spending. Never throws — a bad key/connection is a result.
     *
     * @return array{ok: bool, reason: string, message: string, detail: ?string}
     */
    public function checkConnection(?string $overrideKey = null): array;

    /**
     * Test whether a SPECIFIC model id is reachable/usable on this provider (the admin
     * "does this model work?" probe). Never throws; classifies not_configured / invalid_key /
     * model_not_found / timeout / error. The reason 'model_not_found' is the answer to the
     * common 404 "the model does not exist or you do not have access". $baseUrl overrides the
     * provider host (per-model region); providers without regional endpoints (OpenRouter)
     * ignore it.
     *
     * @return array{ok: bool, reason: string, message: string, detail: ?string}
     */
    public function checkModel(string $modelId, ?string $overrideKey = null, ?string $baseUrl = null): array;
}
