<?php

namespace App\Domain\Scan\Fetch;

/**
 * GuardedResponse — the terminal result of a guarded (SSRF-checked, pinned,
 * byte-capped, redirect-followed) HTTP exchange.
 *
 * Carries the final status, the bounded body, the final (post-redirect) URL the
 * body belongs to, and whether the body was truncated at the byte cap. The fetchers
 * map this into a FetchResult / robots rules / sidecar payload.
 */
final readonly class GuardedResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public string $finalUrl,
        public bool $truncated,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }
}
