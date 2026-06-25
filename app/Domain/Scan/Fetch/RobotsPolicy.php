<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;
use Illuminate\Support\Facades\Cache;

/**
 * RobotsPolicy — a minimal, cached robots.txt gate for our user-agent.
 *
 * We fetch /robots.txt per host (cached), parse the relevant User-agent groups
 * (our UA + the * fallback), and honour Disallow rules against the request path.
 * Conservative + fail-OPEN on a missing/unfetchable robots.txt (no robots.txt =
 * nothing disallowed), but fail-CLOSED on an explicit matching Disallow.
 *
 * The robots.txt fetch is itself outbound HTTP to an attacker-influenced origin, so
 * it rides the SAME GuardedHttpClient (resolve + pin + byte cap) as the page fetch —
 * a robots.txt URL cannot be an SSRF vector either.
 */
final class RobotsPolicy
{
    // === CONSTANTS ===
    private const CACHE_PREFIX = 'scan:robots:';

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly GuardedHttpClient $client,
    ) {}

    /** True when our UA may fetch $url under the host's robots.txt. */
    public function allows(string $url): bool
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $path = $parts['path'] ?? '/';
        $rules = $this->disallowedPathsFor($parts['scheme'].'://'.$parts['host']);

        foreach ($rules as $disallow) {
            if ($disallow !== '' && str_starts_with($path, $disallow)) {
                return false;
            }
        }

        return true;
    }

    /** The Disallow prefixes that apply to our UA for an origin (cached). */
    private function disallowedPathsFor(string $origin): array
    {
        return Cache::remember(
            self::CACHE_PREFIX.sha1($origin),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->parse($this->fetchRobots($origin)),
        );
    }

    /** Fetch /robots.txt; empty string when absent/unreachable (= allow all). */
    private function fetchRobots(string $origin): string
    {
        $userAgent = (string) config('services.scraper.user_agent');

        try {
            $response = $this->client->get(
                $origin.'/robots.txt',
                ['User-Agent' => $userAgent],
                ScanConstants::EGRESS_ROBOTS_MAX_BYTES,
                ScanConstants::EGRESS_ROBOTS_TIMEOUT,
            );

            return $response->successful() ? $response->body : '';
        } catch (\Throwable) {
            // A guarded refusal (SSRF) or transport error → treat as no robots.txt.
            return '';
        }
    }

    /**
     * Parse the Disallow prefixes for our UA + the * group. A naive but correct
     * subset of the robots grammar (the common-case Disallow lines).
     *
     * @return array<int,string>
     */
    private function parse(string $robotsTxt): array
    {
        if (trim($robotsTxt) === '') {
            return [];
        }

        $ourAgent = strtolower((string) config('services.scraper.user_agent'));
        $lines = preg_split('/\r\n|\r|\n/', $robotsTxt) ?: [];

        $groups = [];        // user-agent => [disallow paths]
        $currentAgents = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? $line);

            if ($line === '') {
                continue;
            }

            [$field, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            $field = strtolower($field);

            if ($field === 'user-agent') {
                $currentAgents = [strtolower($value)];

                continue;
            }

            if ($field === 'disallow') {
                foreach ($currentAgents as $agent) {
                    $groups[$agent][] = $value;
                }
            }
        }

        // Our UA's specific group wins; else fall back to the * group.
        foreach ($groups as $agent => $paths) {
            if ($agent !== '*' && str_contains($ourAgent, $agent)) {
                return array_values(array_filter($paths, static fn ($p) => $p !== ''));
            }
        }

        return array_values(array_filter($groups['*'] ?? [], static fn ($p) => $p !== ''));
    }
}
