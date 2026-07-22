<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\KlingImageClient;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\ProviderRouter;
use App\Domain\Playground\PlaygroundImageResult;
use App\Models\AiOperation;

/**
 * StoryboardFrameImageCaller — the provider-facing half of storyboard FRAME generation.
 *
 * Unlike the raw playground runner (which deliberately sends nothing but prompt + inputs),
 * this caller forwards the RESOLVED config's quality, aspect ratio and sampler knobs
 * (seed/temperature/top_p) in each provider's own body shape — so "image_quality: high" and
 * the PROJECT's aspect actually reach the model. Every value comes from OperationConfig
 * (DB-resolved), never a literal. Multi-image: the chain anchor + @tag references ride as
 * input images for the edit-capable models.
 */
final class StoryboardFrameImageCaller
{
    // === CONSTANTS ===
    // Provider logging key — the real operation this caller serves.
    private const OP_KEY = AiOperation::KEY_STORYBOARD_FRAME_IMAGE;

    // OpenRouter image output modality.
    private const MODALITIES = ['image', 'text'];

    // BytePlus/Seedream image body knobs (quality → render size).
    private const BYTEPLUS_RESPONSE_FORMAT = 'b64_json';

    private const BYTEPLUS_DEFAULT_SIZE = '2K';

    private const BYTEPLUS_SEQUENTIAL = 'disabled';

    private const BYTEPLUS_QUALITY_SIZE = [
        'high' => '2K',
        'standard' => '1K',
        'low' => '1K',
    ];

    // xAI/Grok is text-to-image only.
    private const XAI_IMAGE_COUNT = 1;

    // fal has no free-form ratio: the aspect maps onto its image_size enum (the raw ratio also
    // rides as aspect_ratio for models that declare it; fal ignores undeclared fields).
    private const FAL_IMAGE_SIZES = [
        '16:9' => 'landscape_16_9',
        '9:16' => 'portrait_16_9',
        '4:3' => 'landscape_4_3',
        '3:4' => 'portrait_4_3',
        '1:1' => 'square_hd',
    ];

    // The sampler knobs forwarded from the resolved params bag (OpenRouter chat shape).
    private const SAMPLER_KNOBS = ['seed', 'temperature', 'top_p'];

    // Kling body keys forwarded from the params bag — ONLY when an input image rides along
    // (image_reference without an image is a Kling 400). The client passes unknown keys
    // through verbatim, so these are admin-tunable without a deploy.
    private const KLING_PASSTHROUGH = ['image_reference', 'image_fidelity', 'n'];

    private const NO_IMAGE_MESSAGE = 'The provider returned no usable image.';

    public function __construct(
        private readonly ProviderRouter $router,
    ) {}

    /**
     * Run one frame generation and return the bytes + parsed cost + model used.
     *
     * @param  array<int,ImagePayload>  $inputs  chain anchor + reference images (signed urls)
     * @param  ?string  $fallbackModel  a SAME-provider fallback (e.g. Kling v2-1 when v3
     *                                  refuses) — the caller decides provider compatibility
     */
    public function run(
        OperationConfig $config,
        string $provider,
        string $model,
        string $prompt,
        array $inputs,
        ?int $priceHintMicroUsd,
        ?string $fallbackModel = null,
    ): PlaygroundImageResult {
        $client = $this->router->for($provider);

        $response = $client->callWithFallback(
            self::OP_KEY,
            $model,
            $fallbackModel,
            fn (string $m): array => $this->buildBody($config, $provider, $m, $prompt, $inputs),
        );

        [$bytes, $mime] = $client->extractImage($response);

        if ($bytes === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                self::NO_IMAGE_MESSAGE,
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
    private function buildBody(OperationConfig $config, string $provider, string $model, string $prompt, array $inputs): array
    {
        return match ($provider) {
            ImageGenerationProvider::PROVIDER_BYTEPLUS => $this->bytePlusBody($config, $model, $prompt, $inputs),
            ImageGenerationProvider::PROVIDER_XAI => $this->xaiBody($model, $prompt),
            ImageGenerationProvider::PROVIDER_FAL => $this->falBody($config, $model, $prompt, $inputs),
            ImageGenerationProvider::PROVIDER_KLING => $this->klingBody($config, $model, $prompt, $inputs),
            default => $this->openRouterBody($config, $model, $prompt, $inputs),
        };
    }

    /**
     * OpenRouter chat body: prompt + input images as content parts, with quality, aspect and
     * the sampler knobs — the shape gemini-3-pro-image (Nano Banana Pro) reads.
     *
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function openRouterBody(OperationConfig $config, string $model, string $prompt, array $inputs): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($inputs as $image) {
            $content[] = $image->toContentPart();
        }

        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'modalities' => self::MODALITIES,
        ];

        if ($config->imageQuality !== null) {
            $body['quality'] = $config->imageQuality;
        }

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
        }

        return $this->applySampler($body, $config);
    }

    /**
     * fal queue body ('model' is popped by the client — the id is the URL path; the client
     * inlines the urls as data URIs and strips image fields for text-to-image models).
     *
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function falBody(OperationConfig $config, string $model, string $prompt, array $inputs): array
    {
        $body = ['model' => $model, 'prompt' => $prompt];

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
            $size = self::FAL_IMAGE_SIZES[$config->aspectRatio] ?? null;

            if ($size !== null) {
                $body['image_size'] = $size;
            }
        }

        $urls = array_values(array_map(static fn (ImagePayload $i): string => $i->url, $inputs));
        if ($urls !== []) {
            $body['image_url'] = $urls[0];
            $body['image_urls'] = $urls;
        }

        if (array_key_exists('seed', $config->params)) {
            $body['seed'] = $config->params['seed'];
        }

        return $body;
    }

    /**
     * BytePlus/Seedream images body: quality maps to the render size; seed forwarded.
     *
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function bytePlusBody(OperationConfig $config, string $model, string $prompt, array $inputs): array
    {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => self::BYTEPLUS_QUALITY_SIZE[$config->imageQuality] ?? self::BYTEPLUS_DEFAULT_SIZE,
            'sequential_image_generation' => self::BYTEPLUS_SEQUENTIAL,
            'response_format' => self::BYTEPLUS_RESPONSE_FORMAT,
            'watermark' => false,
        ];

        $urls = array_map(static fn (ImagePayload $i): string => $i->url, $inputs);
        if ($urls !== []) {
            $body['image'] = array_values($urls);
        }

        if (array_key_exists('seed', $config->params)) {
            $body['seed'] = $config->params['seed'];
        }

        return $body;
    }

    /** xAI/Grok is text-to-image (no input images). @return array<string,mixed> */
    private function xaiBody(string $model, string $prompt): array
    {
        return [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => self::BYTEPLUS_RESPONSE_FORMAT,
            'n' => self::XAI_IMAGE_COUNT,
        ];
    }

    /**
     * Kling body (its client submits + polls behind the sync contract and inlines the inputs
     * as raw base64).
     *
     * @param  array<int,ImagePayload>  $inputs
     * @return array<string,mixed>
     */
    private function klingBody(OperationConfig $config, string $model, string $prompt, array $inputs): array
    {
        $body = [
            KlingImageClient::KEY_MODEL => $model,
            KlingImageClient::KEY_PROMPT => $prompt,
        ];

        $urls = array_values(array_map(static fn (ImagePayload $i): string => $i->url, $inputs));
        if ($urls !== []) {
            $body[KlingImageClient::KEY_IMAGE_URLS] = $urls;

            // Reference-tuning knobs ride ONLY alongside an actual reference image.
            foreach (self::KLING_PASSTHROUGH as $knob) {
                if (array_key_exists($knob, $config->params)) {
                    $body[$knob] = $config->params[$knob];
                }
            }
        }

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
        }

        return $body;
    }

    /**
     * Forward the sampler knobs from the resolved params bag — config, never a literal.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function applySampler(array $body, OperationConfig $config): array
    {
        foreach (self::SAMPLER_KNOBS as $knob) {
            if (array_key_exists($knob, $config->params)) {
                $body[$knob] = $config->params[$knob];
            }
        }

        return $body;
    }
}
