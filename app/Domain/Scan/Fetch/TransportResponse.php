<?php

namespace App\Domain\Scan\Fetch;

/**
 * TransportResponse — the result of a SINGLE guarded, pinned, byte-capped hop.
 *
 * Carries the HTTP status, the Location header (for manual redirect following),
 * the bounded body, and a flag for whether the byte cap fired mid-stream. The
 * GuardedHttpClient inspects status/location to follow redirects (re-guarding each
 * hop) and surfaces the body once a non-redirect terminal response arrives.
 */
final readonly class TransportResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $location,
        public bool $truncated,
    ) {}

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true)
            && $this->location !== null
            && $this->location !== '';
    }
}
