<?php

namespace App\Domain\Ai;

/**
 * TryOnGenerationCaller — operation B: try_on_generation to result image BYTES.
 *
 * Given the shopper image + the selected-variant product image + the assembled
 * prompt, calls an image-generation/edit model and returns the result image
 * bytes. Honours image_quality + aspect_ratio + seed/temperature from the
 * resolver bag — never a literal. Returns bytes (not a URL); laravel-backend
 * stores them to media before charging.
 */
final class TryOnGenerationCaller
{
    // === CONSTANTS ===
    // The image output modality OpenRouter image models expect.
    private const MODALITIES = ['image', 'text'];
    private const DATA_URL_PREFIX = 'data:';

    public function __construct(
        private readonly OpenRouterClient $client,
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
        $response = $this->client->callWithFallback(
            $config->operationKey,
            $config->model,
            $config->fallbackModel,
            fn (string $model): array => $this->buildBody($config, $model, $shopperImage, $variantImage, $vars),
        );

        $modelUsed = $this->client->extractModelUsed($response, $config->model);
        [$bytes, $mime] = $this->extractImage($response);

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
            cost: $this->client->parseCost($response, $config->estimatedCostMicroUsd),
            modelUsed: $modelUsed,
            openrouterGenerationId: $this->client->extractGenerationId($response),
        );
    }

    /**
     * Build the chat body for an image generation. quality / aspect / sampler all
     * come from the bag.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildBody(
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

        $body = [
            'model' => $model,
            'messages' => $messages,
            'modalities' => self::MODALITIES,
        ];

        return $this->applyImageParams($body, $config);
    }

    /**
     * Apply image_quality + aspect_ratio + sampler params from the bag. These are
     * config, never service literals; a hardcoded 1024x1024 / fixed seed is the scar.
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

    /**
     * Extract image bytes + mime from the response. Defensive: the image may
     * arrive as message.images[].image_url.url (data URL) OR a content image part
     * OR a top-level data[].b64_json. Returns [null, ''] when none is usable.
     *
     * @return array{0: string|null, 1: string}
     */
    private function extractImage(array $response): array
    {
        $message = $response['choices'][0]['message'] ?? [];

        // 1) Chat image modality: message.images[].image_url.url as a data URL.
        $images = $message['images'] ?? null;

        if (is_array($images)) {
            foreach ($images as $image) {
                $url = $image['image_url']['url'] ?? ($image['url'] ?? null);

                if (is_string($url)) {
                    $decoded = $this->decodeDataUrl($url);

                    if ($decoded !== null) {
                        return $decoded;
                    }
                }
            }
        }

        // 2) Content parts carrying an image_url.
        $content = $message['content'] ?? null;

        if (is_array($content)) {
            foreach ($content as $part) {
                $url = $part['image_url']['url'] ?? null;

                if (is_string($url)) {
                    $decoded = $this->decodeDataUrl($url);

                    if ($decoded !== null) {
                        return $decoded;
                    }
                }
            }
        }

        // 3) Dedicated image endpoint shape: data[].b64_json.
        $b64 = $response['data'][0]['b64_json'] ?? null;

        if (is_string($b64) && $b64 !== '') {
            $bytes = base64_decode($b64, true);

            if ($bytes !== false) {
                return [$bytes, 'image/png'];
            }
        }

        return [null, ''];
    }

    /**
     * Decode a data: URL to [bytes, mime]. Returns null for a non-data URL or
     * undecodable base64.
     *
     * @return array{0: string, 1: string}|null
     */
    private function decodeDataUrl(string $url): ?array
    {
        if (! str_starts_with($url, self::DATA_URL_PREFIX)) {
            return null;
        }

        // data:image/png;base64,XXXX
        if (! preg_match('#^data:(?<mime>[^;]+);base64,(?<data>.+)$#s', $url, $m)) {
            return null;
        }

        $bytes = base64_decode($m['data'], true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return [$bytes, $m['mime']];
    }
}
