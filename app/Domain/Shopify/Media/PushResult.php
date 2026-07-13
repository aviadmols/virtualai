<?php

namespace App\Domain\Shopify\Media;

/**
 * PushResult — the TYPED outcome of a merchant asking to push / re-push / undo.
 *
 * Every refusal is a RESULT, never an exception and never a 500: the studio renders the right
 * notification (not approved yet, already in the store, still in flight, not a Shopify product).
 * The panel never has to interpret a stack trace.
 */
final readonly class PushResult
{
    // === CONSTANTS ===
    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_NOT_APPROVED = 'not_approved';

    public const REASON_ALREADY_PUSHED = 'already_pushed';

    public const REASON_IN_FLIGHT = 'in_flight';

    public const REASON_NOT_SHOPIFY = 'not_shopify';

    public const REASON_NOTHING_TO_UNDO = 'nothing_to_undo';

    private function __construct(
        public bool $queued,
        public ?string $deniedReason = null,
    ) {}

    public static function queued(): self
    {
        return new self(true);
    }

    public static function denied(string $reason): self
    {
        return new self(false, $reason);
    }

    public function wasDenied(): bool
    {
        return ! $this->queued;
    }
}
