<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;

/**
 * UrlGuard — the SSRF gate. A "product URL" is attacker-controllable, so before
 * ANY fetch we assert it is an http(s) URL whose host RESOLVES only to public IPs.
 *
 * Two layers, both load-bearing:
 *   1. STRING checks (isPublicHttpUrl): scheme allow-list, blocked literal hosts,
 *      and obvious private/obfuscated IP literals (octal/hex/dword normalised).
 *   2. RESOLUTION checks (resolveAndValidate): resolve the host via an injectable
 *      HostResolver and refuse if ANY resolved A/AAAA address is private, loopback,
 *      link-local (incl. 169.254.169.254) or reserved. The validated IP is RETURNED
 *      so the caller can PIN the connection to it — closing the DNS-rebinding /
 *      TOCTOU window where the name re-resolves to a private IP between check and
 *      fetch, and the redirect-hop window.
 *
 * The string layer is pure (no network) and keeps the cheap, fast refusals.
 */
final class UrlGuard
{
    // === CONSTANTS ===
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // Hostnames that are never public, refused outright.
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'metadata.google.internal',
        'metadata',
    ];

    /**
     * Cheap, network-free first pass: a safe, public-LOOKING http(s) URL.
     * Returns false on a bad scheme, a blocked host, or a private/obfuscated IP
     * literal. A hostname that PASSES here is still resolved by resolveAndValidate.
     */
    public static function isPublicHttpUrl(string $url): bool
    {
        $host = self::hostOf($url);
        if ($host === null) {
            return false;
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return false;
        }

        // An IP literal (canonical OR obfuscated octal/hex/dword) must be public.
        $normalised = IpNormaliser::normalise($host);
        if ($normalised !== null) {
            return IpNormaliser::isPublic($normalised);
        }

        // Bare hostnames with no dot (intranet names) are not public PDPs.
        if (! str_contains($host, '.')) {
            return false;
        }

        return true;
    }

    /**
     * The full SSRF gate: string checks, then DNS resolution + per-IP public check.
     * Returns the validated, pinnable IP set on success; throws on any refusal.
     *
     * @return array<int,string> the resolved public IP literals to pin to.
     */
    public static function resolveAndValidate(string $url, HostResolver $resolver): array
    {
        if (! self::isPublicHttpUrl($url)) {
            throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
        }

        $host = self::hostOf($url);
        if ($host === null) {
            throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
        }

        $ips = $resolver->resolve($host);

        // Unresolvable host → cannot prove it is public → refuse.
        if ($ips === []) {
            throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
        }

        foreach ($ips as $ip) {
            if (! IpNormaliser::isPublic($ip)) {
                // Any resolved private/metadata address poisons the whole host.
                throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
            }
        }

        return $ips;
    }

    /**
     * Resolve + return pinnable IPs for a TRUSTED operator destination (the render
     * sidecar) WITHOUT the private-IP rejection — the sidecar legitimately lives on
     * an internal address. Still enforces the scheme/host shape and pins to the
     * resolved IP so the connection cannot be re-targeted. NEVER call this with a
     * merchant-supplied URL.
     *
     * @return array<int,string>
     */
    public static function resolvePinned(string $url, HostResolver $resolver): array
    {
        $host = self::hostOf($url);
        if ($host === null) {
            throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
        }

        $ips = $resolver->resolve($host);
        if ($ips === []) {
            throw FetchException::failed(ScanConstants::FAIL_RENDER_EMPTY);
        }

        return $ips;
    }

    /** Assert the URL is fetchable by STRING rules; throws a typed refusal otherwise. */
    public static function assertFetchable(string $url): void
    {
        if (! self::isPublicHttpUrl($url)) {
            throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
        }
    }

    /** The lower-cased host of a valid http(s) URL, or null. */
    private static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        return strtolower($parts['host']);
    }
}
