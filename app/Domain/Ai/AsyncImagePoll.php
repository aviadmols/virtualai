<?php

namespace App\Domain\Ai;

/**
 * AsyncImagePoll — the outcome of ONE poll tick against a provider queue ticket.
 *
 * Three states, and only three: still rendering, done (the decoded provider response is
 * attached), or terminally failed (with the provider's reason). A TRANSPORT failure is NOT
 * one of them — the provider adapter throws an OpenRouterException for that, so the poller
 * can tell "the render is still going / has failed" (a business outcome) apart from "I could
 * not reach the provider right now" (retry the poll).
 */
final readonly class AsyncImagePoll
{
    // === CONSTANTS ===
    public const STATE_PENDING = 'pending';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    /**
     * @param  array<string,mixed>  $response  the decoded provider result (only when succeeded)
     */
    private function __construct(
        public string $state,
        public array $response = [],
        public string $message = '',
    ) {}

    /** The render is still queued/in progress upstream. */
    public static function pending(): self
    {
        return new self(self::STATE_PENDING);
    }

    /** @param  array<string,mixed>  $response */
    public static function succeeded(array $response): self
    {
        return new self(self::STATE_SUCCEEDED, $response);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATE_FAILED, [], $message);
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isSucceeded(): bool
    {
        return $this->state === self::STATE_SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }
}
