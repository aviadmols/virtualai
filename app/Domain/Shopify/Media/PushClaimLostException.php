<?php

namespace App\Domain\Shopify\Media;

use RuntimeException;

/**
 * PushClaimLostException — this worker's push lease was reclaimed out from under it.
 *
 * NOT a failure. A push whose worker looked lost (isPushStuck) was legitimately taken over by a
 * fresh claim; when the "lost" worker turns out to be merely slow and comes back, it re-proves its
 * claim immediately before minting Shopify media, finds it gone, and throws this. The job catches
 * it and STANDS DOWN without touching the asset: the holder of the live lease owns the outcome.
 * This is guard #3 of the exactly-one-media-per-asset wall (see PushProductMediaJob).
 */
final class PushClaimLostException extends RuntimeException
{
    // === CONSTANTS ===
    private const MSG = 'Push lease on product asset #%d is held by another worker; standing down without minting.';

    public static function for(int $productAssetId): self
    {
        return new self(sprintf(self::MSG, $productAssetId));
    }
}
