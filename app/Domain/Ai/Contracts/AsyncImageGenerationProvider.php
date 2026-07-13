<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\AsyncImagePoll;
use App\Domain\Ai\AsyncImageTicket;

/**
 * AsyncImageGenerationProvider — the SUBMIT/POLL seam for an upstream whose image renders are
 * a QUEUE (fal today; any future task-API upstream tomorrow).
 *
 * Why it exists: the synchronous ImageGenerationProvider contract hides submit->poll->result
 * inside one blocking call, which is right for a shopper-facing try-on (one image, seconds)
 * but wrong for a merchant BULK batch (hundreds of images, minutes each). Blocking a worker
 * for the whole render forces a "must finish inside the job timeout" design — and a job that
 * times out mid-render either strands a paid image or re-runs the paid call.
 *
 * With this seam the lifecycle becomes durable: SUBMIT once (the request id is persisted), then
 * POLL as many short ticks as needed. A blip retries the POLL — never the submit.
 *
 * A provider that does NOT implement this interface is driven synchronously by the same job
 * shape (submit + result in one step). The adapter decides; the pipeline is uniform.
 */
interface AsyncImageGenerationProvider extends ImageGenerationProvider
{
    /**
     * Submit ONE generation to the provider queue and return its ticket. Throws a classified
     * OpenRouterException on any submit failure (so the caller may step to the fallback model
     * or provider) — it never returns a half-ticket.
     *
     * $idempotencyKey is OUR deterministic asset key. An upstream that supports a request-level
     * idempotency key sends it; one that does not is still safe, because the submit-once wall is
     * enforced on our side (the job is ShouldBeUnique on this key, and a row-locked asset that
     * already carries a provider_request_id never submits again).
     *
     * @param  array<string,mixed>  $body  the provider-shaped request body (from the caller)
     */
    public function submitAsync(string $operationKey, string $model, array $body, string $idempotencyKey): AsyncImageTicket;

    /**
     * ONE poll tick against a ticket: pending | succeeded (with the decoded response) | failed.
     * A TRANSPORT problem throws a classified OpenRouterException (the poller retries the poll);
     * a provider-side terminal failure returns AsyncImagePoll::failed().
     */
    public function pollAsync(AsyncImageTicket $ticket, string $operationKey): AsyncImagePoll;
}
