<?php

namespace App\Domain\Playground;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\ProviderRouter;

/**
 * PlaygroundImageRunner — a RAW (un-templated) image generation for the admin playground.
 *
 * Unlike the money-path callers there is NO OperationConfig, prompt template, or credit ledger:
 * the admin's literal prompt + N input images go straight to the chosen provider. Builds the
 * provider-appropriate request body (OpenRouter chat, BytePlus images, xAI text-to-image) and
 * delegates the call + image/cost extraction to the ImageGenerationProvider via ProviderRouter.
 * Flat-rate providers (BytePlus, xAI) use the supplied per-image price for the displayed cost.
 */
final class PlaygroundImageRunner
{
    // === CONSTANTS ===
    // A stable operation key for provider logging (this path has no real AiOperation).
    private const OP_KEY = 'playground_image';

    private const MODALITIES = ['image', 'text'];
    private const BYTEPLUS_SIZE = '2K';
    private const BYTEPLUS_SEQUENTIAL = 'disabled';
    private const RESPONSE_FORMAT = 'b64_json';
    private const XAI_IMAGE_COUNT = 1;

    public function __construct(
        private readonly ProviderRouter $router,
    ) {}

    /**
     * Run one image generation and return the bytes + parsed cost + model used.
     *
     * @param  array<int,ImagePayload>  $inputs  input images (signed https urls)
     */
    public function run(string $provider, string $model, string $prompt, array $inputs, ?int $priceHintMicroUsd): PlaygroundImageResult
    {
        $client = $this->router->for($provider);

        $response = $client->callWithFallback(
            self::OP_KEY,
            $model,
            null,
            fn (string $m): array => $this->buildBody($provider, $m, $prompt, $inputs),
        );

        [$bytes, $mime] = $client->extractImage($response);

        if ($bytes === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                'The provider returned no usable image.',
                modelUsed: $model,
            );
        }

        return new PlaygroundImageResult(
            imageBytes: $bytes,
            mimeType: $mime,
            cost: $client->parseCost($response, $priceHintMicroUsd),
            modelUsed: $client->extractModelUsed($response, $model),
        );
    }

    /**
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function buildBody(string $provider, string $model, string $prompt, array $inputs): array
    {
        return match ($provider) {
            ImageGenerationProvider::PROVIDER_BYTEPLUS => $this->bytePlusBody($model, $prompt, $inputs),
            ImageGenerationProvider::PROVIDER_XAI => $this->xaiBody($model, $prompt),
            default => $this->openRouterBody($model, $prompt, $inputs),
        };
    }

    /**
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function openRouterBody(string $model, string $prompt, array $inputs): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($inputs as $image) {
            $content[] = $image->toContentPart();
        }

        return [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'modalities' => self::MODALITIES,
        ];
    }

    /**
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function bytePlusBody(string $model, string $prompt, array $inputs): array
    {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => self::BYTEPLUS_SIZE,
            'sequential_image_generation' => self::BYTEPLUS_SEQUENTIAL,
            'response_format' => self::RESPONSE_FORMAT,
            'watermark' => false,
        ];

        $urls = array_map(static fn (ImagePayload $i): string => $i->url, $inputs);
        if ($urls !== []) {
            $body['image'] = array_values($urls);
        }

        return $body;
    }

    /** xAI/Grok is text-to-image (no input image is sent). @return array<string,mixed> */
    private function xaiBody(string $model, string $prompt): array
    {
        return [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => self::RESPONSE_FORMAT,
            'n' => self::XAI_IMAGE_COUNT,
        ];
    }
}
