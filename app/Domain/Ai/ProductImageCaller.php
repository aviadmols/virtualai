<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AsyncImageGenerationProvider;
use App\Domain\Ai\Contracts\ImageGenerationProvider;

/**
 * ProductImageCaller — the provider-facing half of the Product Image Studio: it turns a
 * RESOLVED config bag + ONE source product photo into either a queued ticket or a finished
 * image, and it is the ONLY place that knows each upstream's body shape.
 *
 * Everything it sends comes from the resolver bag (model, prompt, quality, aspect ratio,
 * sampler params) — no model id, prompt or knob is ever a literal here. Prompt placeholders
 * are substituted with strtr (OperationConfig::substituteUser), NEVER Blade::render.
 *
 * ASYNC-FIRST: if the resolved provider implements AsyncImageGenerationProvider (fal), submit()
 * returns a TICKET and the caller's job hands off to the poller. If it does not (OpenRouter,
 * BytePlus, Kling), the same submit() runs the blocking call and returns a finished RESULT.
 * The pipeline is uniform; the adapter decides.
 *
 * Cross-provider fallback happens at SUBMIT time only: if the primary model's submit fails, we
 * step to the fallback model/provider. A failure discovered later (while polling) is terminal —
 * re-submitting a render we may already be paying for is exactly the double-spend this design
 * exists to prevent.
 */
final class ProductImageCaller
{
    // === CONSTANTS ===
    // OpenRouter image output modality.
    private const MODALITIES = ['image', 'text'];

    // BytePlus/Seedream image body knobs.
    private const BYTEPLUS_RESPONSE_FORMAT = 'b64_json';

    private const BYTEPLUS_DEFAULT_SIZE = '2K';

    private const BYTEPLUS_SEQUENTIAL = 'disabled';

    private const BYTEPLUS_QUALITY_SIZE = [
        'high' => '2K',
        'standard' => '1K',
        'low' => '1K',
    ];

    // fal has no free-form ratio: the aspect maps onto its image_size enum (the raw ratio also
    // rides as aspect_ratio for the models that declare it; fal ignores undeclared fields).
    private const FAL_IMAGE_SIZES = [
        '16:9' => 'landscape_16_9',
        '9:16' => 'portrait_16_9',
        '4:3' => 'landscape_4_3',
        '3:4' => 'portrait_4_3',
        '1:1' => 'square_hd',
    ];

    // The sampler knobs forwarded from the resolved params bag (OpenRouter shape).
    private const SAMPLER_KNOBS = ['seed', 'temperature', 'top_p'];

    private const NO_IMAGE_MESSAGE = '%s response carried no usable image bytes.';

    private const NO_PROVIDER_MESSAGE = 'No provider accepted the %s submit.';

    public function __construct(
        private readonly ProviderRouter $router,
    ) {}

    /**
     * SUBMIT the transform. Tries the primary provider/model, then the fallback (a submit-time
     * step only). Returns a queued ticket (async upstream) or a finished result (sync upstream).
     *
     * @param  array<string,string|int|float|null>  $vars  prompt placeholders
     */
    public function submit(
        OperationConfig $config,
        ImagePayload $source,
        array $vars,
        string $idempotencyKey,
    ): ProductImageSubmission {
        $attempts = [[$config->provider, $config->model, $config->flatRatePriceMicroUsd()]];

        if ($config->fallbackModel !== null && $config->fallbackModel !== '') {
            $attempts[] = [$config->fallbackProvider, $config->fallbackModel, $config->fallbackFlatRatePriceMicroUsd()];
        }

        $last = null;

        foreach ($attempts as [$providerId, $modelId, $flatRatePrice]) {
            try {
                return $this->attempt($config, $providerId, $modelId, $flatRatePrice, $source, $vars, $idempotencyKey);
            } catch (OpenRouterException $e) {
                $last = $e; // step to the fallback provider/model, if any
            }
        }

        throw $last ?? OpenRouterException::make(
            OpenRouterException::CODE_PROVIDER_OUTAGE,
            sprintf(self::NO_PROVIDER_MESSAGE, $config->operationKey),
        );
    }

    /**
     * ONE poll tick against a persisted ticket. Never re-submits: the ticket IS the request the
     * upstream already accepted. A transport blip throws (the poller re-polls); a provider-side
     * terminal failure returns a typed failed poll.
     */
    public function poll(AsyncImageTicket $ticket, OperationConfig $config): AsyncImagePoll
    {
        $provider = $this->router->for($ticket->provider);

        if (! $provider instanceof AsyncImageGenerationProvider) {
            return AsyncImagePoll::failed(sprintf('Provider "%s" cannot be polled.', $ticket->provider));
        }

        return $provider->pollAsync($ticket, $config->operationKey);
    }

    /**
     * Turn a SUCCEEDED poll response into the finished result (bytes + honest cost). The
     * flat-rate price is the one that was locked in at SUBMIT time for the model that actually
     * ran — so the finalize charges exactly what the submit priced.
     */
    public function resultFromPoll(
        AsyncImagePoll $poll,
        AsyncImageTicket $ticket,
        ?int $flatRatePriceMicroUsd,
    ): ProductImageResult {
        $provider = $this->router->for($ticket->provider);
        [$bytes, $mime] = $provider->extractImage($poll->response);

        if ($bytes === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                sprintf(self::NO_IMAGE_MESSAGE, $ticket->provider),
                modelUsed: $ticket->model,
            );
        }

        return new ProductImageResult(
            imageBytes: $bytes,
            mimeType: $mime,
            cost: $provider->parseCost($poll->response, $flatRatePriceMicroUsd),
            modelUsed: $provider->extractModelUsed($poll->response, $ticket->model),
            provider: $ticket->provider,
            providerGenerationId: $provider->extractGenerationId($poll->response) ?? $ticket->requestId,
        );
    }

    /**
     * One provider/model attempt. Throws a classified OpenRouterException on any failure so
     * submit() can step to the fallback.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    private function attempt(
        OperationConfig $config,
        string $providerId,
        string $modelId,
        ?int $flatRatePriceMicroUsd,
        ImagePayload $source,
        array $vars,
        string $idempotencyKey,
    ): ProductImageSubmission {
        $provider = $this->router->for($providerId);
        $prompt = $config->substituteUser($vars); // strtr — never Blade::render (RCE prevention)
        $body = $this->buildBody($config, $providerId, $modelId, $source, $vars);

        // ASYNC upstream: submit and hand back the ticket — the render finishes on the poller.
        if ($provider instanceof AsyncImageGenerationProvider) {
            $ticket = $provider->submitAsync($config->operationKey, $modelId, $body, $idempotencyKey);

            return ProductImageSubmission::queued($ticket, $flatRatePriceMicroUsd, $prompt);
        }

        // SYNC upstream: the same job shape, but the result arrives in one step.
        $response = $provider->callWithFallback(
            $config->operationKey,
            $modelId,
            null, // cross-provider stepping is submit()'s job
            fn (string $model): array => $this->buildBody($config, $providerId, $model, $source, $vars),
        );

        $modelUsed = $provider->extractModelUsed($response, $modelId);
        [$bytes, $mime] = $provider->extractImage($response);

        if ($bytes === null) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_INVALID_IMAGE,
                sprintf(self::NO_IMAGE_MESSAGE, $config->operationKey),
                modelUsed: $modelUsed,
            );
        }

        return ProductImageSubmission::completed(
            new ProductImageResult(
                imageBytes: $bytes,
                mimeType: $mime,
                cost: $provider->parseCost($response, $flatRatePriceMicroUsd),
                modelUsed: $modelUsed,
                provider: $providerId,
                providerGenerationId: $provider->extractGenerationId($response),
            ),
            $flatRatePriceMicroUsd,
            $prompt,
        );
    }

    /**
     * The provider-shaped request body for ONE source image + the resolved prompt.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildBody(
        OperationConfig $config,
        string $providerId,
        string $model,
        ImagePayload $source,
        array $vars,
    ): array {
        return match ($providerId) {
            ImageGenerationProvider::PROVIDER_BYTEPLUS => $this->buildBytePlusBody($config, $model, $source, $vars),
            ImageGenerationProvider::PROVIDER_FAL => $this->buildFalBody($config, $model, $source, $vars),
            ImageGenerationProvider::PROVIDER_KLING => $this->buildKlingBody($config, $model, $source, $vars),
            default => $this->buildOpenRouterBody($config, $model, $source, $vars),
        };
    }

    /**
     * OpenRouter chat body: the prompt + the single source image as a content part.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildOpenRouterBody(OperationConfig $config, string $model, ImagePayload $source, array $vars): array
    {
        $messages = [];
        $system = $config->substituteSystem($vars);

        if ($system !== null) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $config->substituteUser($vars)],
            $source->toContentPart(),
        ]];

        $body = [
            'model' => $model,
            'messages' => $messages,
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
     * fal queue body (the model id is the URL path; the fal client pops 'model' and inlines the
     * image url as a data URI). System prompt is prepended — fal takes one prompt string.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildFalBody(OperationConfig $config, string $model, ImagePayload $source, array $vars): array
    {
        $body = [
            'model' => $model,
            'prompt' => $this->onePrompt($config, $vars),
            'image_url' => $source->url,
            'image_urls' => [$source->url],
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
     * BytePlus/Seedream images/generations body: one prompt + the source image ref + size.
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildBytePlusBody(OperationConfig $config, string $model, ImagePayload $source, array $vars): array
    {
        $body = [
            'model' => $model,
            'prompt' => $this->onePrompt($config, $vars),
            'size' => self::BYTEPLUS_QUALITY_SIZE[$config->imageQuality] ?? self::BYTEPLUS_DEFAULT_SIZE,
            'sequential_image_generation' => self::BYTEPLUS_SEQUENTIAL,
            'response_format' => self::BYTEPLUS_RESPONSE_FORMAT,
            'watermark' => false,
            'image' => [$source->url],
        ];

        if (array_key_exists('seed', $config->params)) {
            $body['seed'] = $config->params['seed'];
        }

        return $body;
    }

    /**
     * Kling body (its client submits + polls behind the sync contract).
     *
     * @param  array<string,string|int|float|null>  $vars
     * @return array<string,mixed>
     */
    private function buildKlingBody(OperationConfig $config, string $model, ImagePayload $source, array $vars): array
    {
        $body = [
            KlingImageClient::KEY_MODEL => $model,
            KlingImageClient::KEY_PROMPT => $this->onePrompt($config, $vars),
            KlingImageClient::KEY_IMAGE_URLS => [$source->url],
        ];

        if ($config->aspectRatio !== null) {
            $body['aspect_ratio'] = $config->aspectRatio;
        }

        return $body;
    }

    /**
     * The single-prompt upstreams (fal / BytePlus / Kling) take ONE string: system prepended to
     * user, both substituted with strtr.
     *
     * @param  array<string,string|int|float|null>  $vars
     */
    private function onePrompt(OperationConfig $config, array $vars): string
    {
        $prompt = $config->substituteUser($vars);
        $system = $config->substituteSystem($vars);

        return $system !== null && $system !== '' ? $system."\n\n".$prompt : $prompt;
    }

    /**
     * Forward the sampler knobs from the resolved params bag — config, never a service literal.
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
