<?php

namespace App\Domain\Scan\Review;

use RuntimeException;

/**
 * ScanConfirmBlockedException — thrown when the confirm action is asked to confirm
 * a product while the ConfirmGate is still closed (a low / not_detected row remains
 * unreviewed). The no-auto-approve gate enforced SERVER-SIDE: a crafted request
 * that skips the UI cannot confirm an unreviewed scan.
 *
 * Carries the still-blocking row keys so the caller can surface scan.blocked.reason
 * with the exact rows the merchant must still review.
 */
final class ScanConfirmBlockedException extends RuntimeException
{
    private const MESSAGE = 'Confirm blocked: %d scan row(s) still need review (%s).';

    /**
     * @param  array<int,string>  $blockingKeys
     */
    public function __construct(
        public readonly array $blockingKeys,
    ) {
        parent::__construct(sprintf(
            self::MESSAGE,
            count($blockingKeys),
            implode(', ', $blockingKeys),
        ));
    }

    public static function from(ConfirmGate $gate): self
    {
        return new self($gate->blockingKeys);
    }
}
