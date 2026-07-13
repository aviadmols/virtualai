<?php

namespace App\Domain\Ai;

/**
 * ProductImageSubmission — what a SUBMIT returned, in the one shape the pipeline understands.
 *
 * Exactly one of the two is set, and the PROVIDER ADAPTER decides which:
 *  - async  -> a ticket (the render is queued upstream; the poller takes it from here);
 *  - sync   -> a finished result (an upstream that only offers a blocking call, e.g. OpenRouter,
 *              produced the image inside the submit step).
 *
 * The pipeline therefore has ONE uniform job shape for both: submit, then either poll or
 * finalize immediately. `flatRatePriceMicroUsd` is the price that was locked in for the model
 * actually submitted — it is persisted so the later, separate finalize can compute the SAME
 * honest cost for a flat-rate upstream.
 */
final readonly class ProductImageSubmission
{
    private function __construct(
        public string $provider,
        public string $model,
        public ?int $flatRatePriceMicroUsd,
        public string $prompt,
        public ?AsyncImageTicket $ticket = null,
        public ?ProductImageResult $result = null,
    ) {}

    public static function queued(
        AsyncImageTicket $ticket,
        ?int $flatRatePriceMicroUsd,
        string $prompt,
    ): self {
        return new self(
            provider: $ticket->provider,
            model: $ticket->model,
            flatRatePriceMicroUsd: $flatRatePriceMicroUsd,
            prompt: $prompt,
            ticket: $ticket,
        );
    }

    public static function completed(
        ProductImageResult $result,
        ?int $flatRatePriceMicroUsd,
        string $prompt,
    ): self {
        return new self(
            provider: $result->provider,
            model: $result->modelUsed,
            flatRatePriceMicroUsd: $flatRatePriceMicroUsd,
            prompt: $prompt,
            result: $result,
        );
    }

    /** True when the render is queued upstream and a poller must finish it. */
    public function isQueued(): bool
    {
        return $this->ticket !== null;
    }
}
