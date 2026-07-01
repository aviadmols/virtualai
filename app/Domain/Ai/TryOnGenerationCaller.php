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
    // Seedream 4.x/5.x: force a SINGLE output image (no multi-image sequence) for a try-on.
    private const BYTEPLUS_SEQUENTIAL = 'disabled';
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
     * Tries the PRIMARY model, then the FALLBACK — EACH with its own provider, so a BytePlus
     * default can fall back to an OpenRouter/Gemini model (cross-provider). The stepping across
     * models/providers is owned HERE; each provider's callWithFallback still handles the bounded
     * transient RETRIES for its single model. A failed attempt (incl. a 404/bad_request) drops to
     * the next attempt; only the first SUCCESS returns — so the money path never double-charges.
     *
     * @param  array<string,string|int|float|null>  $vars  prompt placeholders
     */
    public function generate(
        OperationConfig $config,
        ImagePayload $shopperImage,
        ImagePayload $variantImage,
        array $vars = [],
    ): TryOnResult {
        $attempts = [[$config->provider, $config->model]];

        if ($config->fallbackModel !== null && $config->fallbackModel !== '') {
            $attempts[] = [$config->fallbackProvider, $config->fallbackModel];
        }

        $last = null;

        foreach ($attempts as [$providerId, $modelId]) {
            try {
                return $this->attempt($config, $providerId, $modelId, $shopperImage, $variantImage, $vars);
            } catch (OpenRouterException $e) {
                $last = $e; // step to the next (fallback) provider/model, if any
            }
        }

        throw $last ?? OpenRouterException::make(
            OpenRouterException::CODE_PROVIDER_OUTAGE,
            sprintf('No provider produced a try-on image for operation %s.', $config->operationKey),
        );
    }

    /**
     * One provider/model attempt: route to the provider, build its request body, call it
     * (with that provider's own transient retries), and extract the image + cost. Throws a
     * classified OpenRouterException on any failure so generate() can step to the fallback.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    private function attempt(
        OperationConfig $config,
        string $providerId,
        string $modelId,
        ImagePayload $shopperImage,
        ImagePayload $variantImage,
        array $vars,
    ): TryOnResult {
        $provider = $this->router->for($providerId);

        $response = $provider->callWithFallback(
            $config->operationKey,
            $modelId,
            null, // cross-provider stepping is generate()'s job; the provider serves one model
            fn (string $model): array => $providerId === ImageGenerationProvider::PROVIDER_BYTEPLUS
                ? $this->buildBytePlusBody($config, $model, $shopperImage, $variantImage, $vars)
                : $this->buildOpenRouterBody($config, $model, $shopperImage, $variantImage, $vars),
        );

        $modelUsed = $provider->extractModelUsed($response, $modelId);
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
            'sequential_image_generation' => self::BYTEPLUS_SEQUENTIAL,
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
