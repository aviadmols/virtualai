<?php

namespace App\Domain\Banners;

use App\Models\Banner;

/**
 * BannerGenerationRequest — the validated inputs to start ONE banner generation attempt.
 *
 * Carries the target Banner (already tenant-bound — the editor creates it under the bound
 * account), the merchant's freeform brief, an OPTIONAL reference image (raw bytes + mime),
 * and the per-generate client_request_id (a fresh id per Generate click; the last segment
 * of the idempotency key, so a double-click collapses but a new iteration does not).
 */
final readonly class BannerGenerationRequest
{
    public function __construct(
        public Banner $banner,
        public string $brief,
        public string $clientRequestId,
        public ?string $referenceBytes = null,
        public ?string $referenceMime = null,
    ) {}

    /** True when the merchant attached a reference image to guide the generation. */
    public function hasReference(): bool
    {
        return $this->referenceBytes !== null && $this->referenceBytes !== ''
            && $this->referenceMime !== null && $this->referenceMime !== '';
    }
}
