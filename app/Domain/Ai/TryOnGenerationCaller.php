<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;

/**
 * TryOnGenerationCaller — operation B: try_on_generation to result image BYTES.
 *
 * Given the shopper image + the selected-variant product image + the assembled prompt,
 * calls the image provider the resolved config points at (OpenRouter or BytePlus) and
 * returns the result image bytes. Provider-agnostic: it builds the provider-appropriate
 * request body and delegates the call + image/cost extraction to the ImageGenerationProvider
 * chosen by ProviderRouter. Honours image_quality + aspect_ratio + seed from the bag —
 * never a literal. Returns bytes (not a URL); laravel-backend stores them before charging.
 */
final class TryOnGenerationCaller
{
    // === CONSTANTS ===
    // The image output modality OpenRouter image models expect.
    private const MODALITIES = ['image', 'text'];

    // BytePlus/Seedream: image_quality -> size token, response format, defaults.
    private const BYTEPLUS_RESPONSE_FORMAT = 'b64_json';
    private const BYTEPLUS_DEFAULT_SIZE = '2K';
    private const BYTEPLUS_QUALITY_SIZE = [
        'high' => '2K',
        'standard' => '1K',
        'low' => '1K',
    ];

    public function __construct(
        private readonly ProviderRouter $router,
    ) {}

    /**
     * Generate a try-on image.
     *
     * @param  array<string,string|int|float|null>  $vars  prompt placeholders
     */
    public function generate(
        OperationConfig $config,
        ImagePayload $shopperImage,
        ImagePayload $variantImage,
        array $vars = [],
    ): TryOnResult {
        $provider = $this->router->for($config->provider);

        $response = $provider->callWithFallback(
            $config->operationKey,
            $config->model,
            $config->fallbackModel,
            fn (string $model): array => $config->provider === ImageGenerationProvider::PROVIDER_BYTEPLUS
                ? $this->buildBytePlusBody($config, $model, $shopperImage, $variantImage, $vars)
                : $this->buildOpenRouterBody($config, $model, $shopperImage, $variantImage, $vars),
        );

        $modelUsed = $provider->extractModelUsed($response, $config->model);
        [$bytes, $mime] = $provider->extractImage($response);

        if ($bytes === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                'try_on_generation response carried no usable image bytes.',
                modelUsed: $modelUsed,
            );
        }

        return new TryOnResult(
            imageBytes: $bytes,
            mimeType: $mime,
            cost: $provider->parseCost($response, $config->estimatedCostMicroUsd),
            modelUsed: $modelUsed,
            openrouterGenerationId: $provider->extractGenerationId($response),
        );
    }

    /**
     * OpenRouter chat body for an image generation. quality / aspect / sampler come from
     * the bag. (Unchanged from the original single-provider path.)
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildOpenRouterBody(
        OperationConfig $config,
        string $model,
        ImagePayload $shopperImage,
        ImagePayload $variantImage,
        array $vars,
    ): array {
        // strtr substitution — NEVER Blade::render (RCE prevention).
        $prompt = $config->substituteUser($vars);

        $userContent = [
            ['type' => 'text', 'text' => $prompt],
            $shopperImage->toContentPart(),
            $variantImage->toContentPart(),
        ];

        $messages = [];
        $system = $config->substituteSystem($vars);

        if ($system !== null) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $this->applyImageParams([
            'model' => $model,
            'messages' => $messages,
            'modalities' => self::MODALITIES,
        ], $config);
    }

    /**
     * BytePlus/Seedream images/generations body: a single prompt (system prepended — no
     * separate system role) + the input image refs (shopper + product) + size/format.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildBytePlusBody(
        OperationConfig $config,
        string $model,
        ImagePayload $shopperImage,
        ImagePayload $variantImage,
        array $vars,
    ): array {
        $prompt = $config->substituteUser($vars);
        $system = $config->substituteSystem($vars);

        if ($system !== null && $system !== '') {
            $prompt = $system."\n\n".$prompt;
        }

        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'image' => [$shopperImage->url, $variantImage->url],
            'size' => self::BYTEPLUS_QUALITY_SIZE[$config->imageQuality] ?? self::BYTEPLUS_DEFAULT_SIZE,
            'response_format' => self::BYTEPLUS_RESPONSE_FORMAT,
            'watermark' => false,
        ];

        if (array_key_exists('seed', $config->params)) {
            $body['seed'] = $config->params['seed'];
        }

        return $body;
    }

    /**
     * Apply image_quality + aspect_ratio + sampler params from the bag (OpenRouter shape).
     * These are config, never service literals; a hardcoded 1024x1024 / fixed seed is the scar.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function applyImageParams(array $body, OperationConfig $config): array
    {
        if ($config->imageQuality !== null) {
            $body['quality'] = $config->imageQuality;
        }

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
        }

        foreach (['seed', 'temperature', 'top_p'] as $knob) {
            if (array_key_exists($knob, $config->params)) {
                $body[$knob] = $config->params[$knob];
            }
        }

        return $body;
    }
}
