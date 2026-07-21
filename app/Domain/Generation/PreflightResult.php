<?php

namespace App\Domain\Generation;

/**
 * PreflightResult — the outcome of the try-on preflight pass (Slice E).
 *
 * Three shapes only:
 *  - pass():            the photo is usable; no extra guidance.
 *  - pass($refinement): the photo is usable AND the vision model produced extra prompt guidance
 *                       (appended to the try-on user prompt for a more faithful render).
 *  - reject($reason):   the photo cannot produce a meaningful try-on — the generation is
 *                       cancelled BEFORE reserve/render (no charge, no free try burned).
 *
 * A preflight that could not run at all (operation missing/inactive, provider error, timeout)
 * NEVER surfaces here — the service fails OPEN and returns pass(), so the try-on pipeline is
 * exactly as reliable as it was without preflight.
 */
final readonly class PreflightResult
{
    private function __construct(
        public bool $usable,
        public ?string $reason,
        public ?string $refinement,
    ) {}

    public static function pass(?string $refinement = null): self
    {
        $refinement = trim((string) $refinement);

        return new self(true, null, $refinement === '' ? null : $refinement);
    }

    public static function reject(string $reason): self
    {
        return new self(false, trim($reason), null);
    }
}
