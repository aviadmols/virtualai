<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;

/**
 * BannerGenerationCaller — operation: banner_generation to result image BYTES.
 *
 * Given the merchant's brief (substituted into the resolved prompt via strtr) and an
 * OPTIONAL single reference image, calls the image provider the resolved config points at
 * (OpenRouter or BytePlus) and returns the banner image bytes. Provider-agnostic and
 * config-driven (model / prompt / quality / aspect ratio / sampler come from the bag, never
 * a literal). Mirrors TryOnGenerationCaller, differing only in that a banner has ONE optional
 * input image (a marketing reference) rather than two required images.
 */
final class BannerGenerationCaller
{
    // === CONSTANTS ===
    // The image output modality OpenRouter image models expect.
    private const MODALITIES = ['image', 'text'];

    // BytePlus/Seedream: image_quality -> size token, response format, defaults.
    private const BYTEPLUS_RESPONSE_FORMAT = 'b64_json';

    private const BYTEPLUS_DEFAULT_SIZE = '2K';

    private const BYTEPLUS_SEQUENTIAL = 'disabled';

    private const BYTEPLUS_QUALITY_SIZE = [
        'high' => '2K',
        'standard' => '1K',
        'low' => '1K',
    ];

    // xAI/Grok images/generations is TEXT-TO-IMAGE: only model + prompt + these two are sent
    // (no size/quality/aspect/seed — xAI rejects unknown params).
    private const XAI_RESPONSE_FORMAT = 'b64_json';

    private const XAI_IMAGE_COUNT = 1;

    // fal has no free-form ratio: the aspect maps onto its image_size enum (and the raw ratio is
    // also sent as aspect_ratio for the models that declare that field; extras are ignored).
    private const FAL_IMAGE_SIZES = [
        '16:9' => 'landscape_16_9',
        '9:16' => 'portrait_16_9',
        '4:3' => 'landscape_4_3',
        '3:4' => 'portrait_4_3',
        '1:1' => 'square_hd',
    ];

    public function __construct(
        private readonly ProviderRouter $router,
    ) {}

    /**
     * Generate a banner image. Tries the PRIMARY model, then the FALLBACK — each with its own
     * provider (cross-provider stepping owned here; each provider handles its single-model
     * transient retries). Only the first SUCCESS returns, so the money path never double-charges.
     *
     * @param  array<string,string|int|float|null>  $vars  prompt placeholders (e.g. brief)
     */
    public function generate(
        OperationConfig $config,
        ?ImagePayload $reference,
        array $vars = [],
    ): BannerResult {
        $attempts = [[$config->provider, $config->model, $config->flatRatePriceMicroUsd()]];

        if ($config->fallbackModel !== null && $config->fallbackModel !== '') {
            $attempts[] = [$config->fallbackProvider, $config->fallbackModel, $config->fallbackFlatRatePriceMicroUsd()];
        }

        $last = null;

        foreach ($attempts as [$providerId, $modelId, $flatRatePriceMicroUsd]) {
            try {
                return $this->attempt($config, $providerId, $modelId, $flatRatePriceMicroUsd, $reference, $vars);
            } catch (OpenRouterException $e) {
                $last = $e; // step to the next (fallback) provider/model, if any
            }
        }

        throw $last ?? OpenRouterException::make(
            OpenRouterException::CODE_PROVIDER_OUTAGE,
            sprintf('No provider produced a banner image for operation %s.', $config->operationKey),
        );
    }

    /**
     * One provider/model attempt. Throws a classified OpenRouterException on any failure so
     * generate() can step to the fallback.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    private function attempt(
        OperationConfig $config,
        string $providerId,
        string $modelId,
        ?int $flatRatePriceMicroUsd,
        ?ImagePayload $reference,
        array $vars,
    ): BannerResult {
        $provider = $this->router->for($providerId);

        $response = $provider->callWithFallback(
            $config->operationKey,
            $modelId,
            null, // cross-provider stepping is generate()'s job
            fn (string $model): array => match ($providerId) {
                ImageGenerationProvider::PROVIDER_BYTEPLUS => $this->buildBytePlusBody($config, $model, $reference, $vars),
                ImageGenerationProvider::PROVIDER_XAI => $this->buildXaiBody($config, $model, $vars),
                ImageGenerationProvider::PROVIDER_FAL => $this->buildFalBody($config, $model, $reference, $vars),
                ImageGenerationProvider::PROVIDER_KLING => $this->buildKlingBody($config, $model, $reference, $vars),
                default => $this->buildOpenRouterBody($config, $model, $reference, $vars),
            },
        );

        $modelUsed = $provider->extractModelUsed($response, $modelId);
        [$bytes, $mime] = $provider->extractImage($response);

        if ($bytes === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                'banner_generation response carried no usable image bytes.',
                modelUsed: $modelUsed,
            );
        }

        return new BannerResult(
            imageBytes: $bytes,
            mimeType: $mime,
            cost: $provider->parseCost($response, $flatRatePriceMicroUsd),
            modelUsed: $modelUsed,
            openrouterGenerationId: $provider->extractGenerationId($response),
        );
    }

    /**
     * OpenRouter chat body for a banner generation. The reference image (if any) is a single
     * content part; quality / aspect / sampler come from the bag.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildOpenRouterBody(
        OperationConfig $config,
        string $model,
        ?ImagePayload $reference,
        array $vars,
    ): array {
        // strtr substitution — NEVER Blade::render (RCE prevention).
        $prompt = $config->substituteUser($vars);

        $userContent = [['type' => 'text', 'text' => $prompt]];

        if ($reference !== null) {
            $userContent[] = $reference->toContentPart();
        }

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
     * BytePlus/Seedream images/generations body: a single prompt (system prepended) + the
     * optional reference image ref + size/format.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildBytePlusBody(
        OperationConfig $config,
        string $model,
        ?ImagePayload $reference,
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
            'size' => self::BYTEPLUS_QUALITY_SIZE[$config->imageQuality] ?? self::BYTEPLUS_DEFAULT_SIZE,
            'sequential_image_generation' => self::BYTEPLUS_SEQUENTIAL,
            'response_format' => self::BYTEPLUS_RESPONSE_FORMAT,
            'watermark' => false,
        ];

        if ($reference !== null) {
            $body['image'] = [$reference->url];
        }

        if (array_key_exists('seed', $config->params)) {
            $body['seed'] = $config->params['seed'];
        }

        return $body;
    }

    /**
     * xAI/Grok images/generations body — TEXT-TO-IMAGE (OpenAI-compatible). A single prompt
     * (system prepended) + response format + count. xAI's endpoint takes NO input image, so a
     * reference image is not sent; the banner is rendered from the brief alone. No
     * size/quality/aspect/seed — xAI rejects unknown params.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildXaiBody(OperationConfig $config, string $model, array $vars): array
    {
        $prompt = $config->substituteUser($vars);
        $system = $config->substituteSystem($vars);

        if ($system !== null && $system !== '') {
            $prompt = $system."\n\n".$prompt;
        }

        return [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => self::XAI_RESPONSE_FORMAT,
            'n' => self::XAI_IMAGE_COUNT,
        ];
    }

    /**
     * fal queue body (the model id is the URL path; the Fal client pops 'model' and inlines the
     * image urls as data URIs): a single prompt (system prepended) + the OPTIONAL reference image
     * + the aspect mapping. A text-to-image fal model ignores/strips the image fields.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildFalBody(
        OperationConfig $config,
        string $model,
        ?ImagePayload $reference,
        array $vars,
    ): array {
        $prompt = $config->substituteUser($vars);
        $system = $config->substituteSystem($vars);

        if ($system !== null && $system !== '') {
            $prompt = $system."\n\n".$prompt;
        }

        $body = ['model' => $model, 'prompt' => $prompt];

        if ($reference !== null) {
            $body['image_url'] = $reference->url;
            $body['image_urls'] = [$reference->url];
        }

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
            $size = self::FAL_IMAGE_SIZES[$config->aspectRatio] ?? null;
            if ($size !== null) {
                $body['image_size'] = $size;
            }
        }

        if (array_key_exists('seed', $config->params)) {
            $body['seed'] = $config->params['seed'];
        }

        return $body;
    }

    /**
     * Kling body (async task API; the client submits + polls behind the sync contract). A single
     * prompt (system prepended) + the OPTIONAL reference image, which the client inlines as raw
     * base64. A kolors-virtual-try-on model is NOT a banner model — it takes no prompt — so the
     * admin must catalogue a Kling IMAGE model for this operation.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildKlingBody(
        OperationConfig $config,
        string $model,
        ?ImagePayload $reference,
        array $vars,
    ): array {
        $prompt = $config->substituteUser($vars);
        $system = $config->substituteSystem($vars);

        if ($system !== null && $system !== '') {
            $prompt = $system."\n\n".$prompt;
        }

        $body = [
            KlingImageClient::KEY_MODEL => $model,
            KlingImageClient::KEY_PROMPT => $prompt,
        ];

        if ($reference !== null) {
            $body[KlingImageClient::KEY_IMAGE_URLS] = [$reference->url];
        }

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
        }

        return $body;
    }

    /**
     * Apply image_quality + aspect_ratio + sampler params from the bag (OpenRouter shape) —
     * config, never service literals.
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
