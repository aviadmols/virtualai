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
        // Each attempt carries its OWN flat-rate price (the resolved per-model cost hint,
        // else the operation estimate). It is the authoritative charge for a flat-rate
        // provider (BytePlus); OpenRouter ignores it (it returns a real inline cost).
        $attempts = [[$config->provider, $config->model, $config->flatRatePriceMicroUsd()]];

        if ($config->fallbackModel !== null && $config->fallbackModel !== '') {
            $attempts[] = [$config->fallbackProvider, $config->fallbackModel, $config->fallbackFlatRatePriceMicroUsd()];
        }

        $last = null;

        foreach ($attempts as [$providerId, $modelId, $flatRatePriceMicroUsd]) {
            try {
                return $this->attempt($config, $providerId, $modelId, $flatRatePriceMicroUsd, $shopperImage, $variantImage, $vars);
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
        ?int $flatRatePriceMicroUsd,
        ImagePayload $shopperImage,
        ImagePayload $variantImage,
        array $vars,
    ): TryOnResult {
        $provider = $this->router->for($providerId);

        $response = $provider->callWithFallback(
            $config->operationKey,
            $modelId,
            null, // cross-provider stepping is generate()'s job; the provider serves one model
            fn (string $model): array => match ($providerId) {
                ImageGenerationProvider::PROVIDER_BYTEPLUS => $this->buildBytePlusBody($config, $model, $shopperImage, $variantImage, $vars),
                ImageGenerationProvider::PROVIDER_XAI => $this->buildXaiBody($config, $model, $vars),
                ImageGenerationProvider::PROVIDER_FAL => $this->buildFalBody($config, $model, $shopperImage, $variantImage, $vars),
                default => $this->buildOpenRouterBody($config, $model, $shopperImage, $variantImage, $vars),
            },
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
            // The flat-rate price is this attempt's authoritative charge for BytePlus;
            // OpenRouter uses its inline cost and treats this only as the unavailable
            // estimate carrier — so parseCost stays honest on both providers.
            cost: $provider->parseCost($response, $flatRatePriceMicroUsd),
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
     * xAI/Grok images/generations body — TEXT-TO-IMAGE (OpenAI-compatible). A single prompt
     * (system prepended) + response format + count. xAI's endpoint takes NO input image, so the
     * shopper/product photos are not sent; a Grok model renders from the prompt alone (it fits
     * banners better than a true try-on). No size/quality/aspect/seed — xAI rejects unknown params.
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
     * image urls as data URIs): a single prompt (system prepended) + BOTH input images (shopper +
     * product — fal edit models see them via image_url/image_urls) + the aspect mapping. A
     * text-to-image fal model ignores the image fields (the client strips them via the catalog).
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildFalBody(
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
            'image_url' => $shopperImage->url,
            'image_urls' => [$shopperImage->url, $variantImage->url],
        ];

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
