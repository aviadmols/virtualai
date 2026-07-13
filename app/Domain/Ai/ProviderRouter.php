<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;

/**
 * ProviderRouter — picks the image-generation client for a provider id. The provider comes
 * from the resolved model's `provider` column (AiOperationResolver), so a Seedream model
 * routes to BytePlus, a Grok model routes to xAI, and a Gemini model routes to OpenRouter,
 * transparently to the caller.
 */
final class ProviderRouter
{
    public function __construct(
        private readonly OpenRouterClient $openRouter,
        private readonly BytePlusImageClient $bytePlus,
        private readonly XaiImageClient $xai,
        private readonly FalImageClient $fal,
        private readonly KlingImageClient $kling,
    ) {}

    public function for(string $provider): ImageGenerationProvider
    {
        return match ($provider) {
            ImageGenerationProvider::PROVIDER_OPENROUTER => $this->openRouter,
            ImageGenerationProvider::PROVIDER_BYTEPLUS => $this->bytePlus,
            ImageGenerationProvider::PROVIDER_XAI => $this->xai,
            ImageGenerationProvider::PROVIDER_FAL => $this->fal,
            ImageGenerationProvider::PROVIDER_KLING => $this->kling,
            default => throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                sprintf('Unknown AI provider "%s".', $provider),
            ),
        };
    }
}
