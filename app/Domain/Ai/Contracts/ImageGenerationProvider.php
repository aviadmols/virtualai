<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\ParsedCost;

/**
 * ImageGenerationProvider — the provider seam for try-on image generation.
 *
 * One implementation per upstream (OpenRouter, BytePlus/Seedream). The caller
 * (TryOnGenerationCaller) speaks ONLY this interface; it never knows which upstream is
 * behind it. Fallback/retry classification stays provider-side (each upstream has its own
 * error envelope + status semantics + response shape).
 */
interface ImageGenerationProvider
{
    // Stable provider ids — also the ai_models.provider enum values.
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_BYTEPLUS = 'byteplus';

    public const PROVIDERS = [self::PROVIDER_OPENROUTER, self::PROVIDER_BYTEPLUS];

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
}
