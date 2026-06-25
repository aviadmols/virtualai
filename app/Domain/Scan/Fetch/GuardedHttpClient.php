<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;

/**
 * GuardedHttpClient — the SINGLE guarded egress entry point for the scan layer.
 *
 * Every outbound HTTP call that targets an attacker-influenced or external URL
 * (the PDP fetch, robots.txt, the render sidecar) goes through here so the SSRF
 * defence cannot be forgotten on one path. Per request it:
 *   1. RESOLVES + VALIDATES the URL (UrlGuard + HostResolver): refuse if the host
 *      resolves to any private/metadata/reserved IP.
 *   2. PINS the connection to the validated IP (transport CURLOPT_RESOLVE) so DNS
 *      cannot rebind to a private address between check and connect.
 *   3. STREAMS the body with a mid-stream byte cap (no full buffering).
 *   4. Follows redirects MANUALLY — each Location is re-run through steps 1-3, so a
 *      302 → http://169.254.169.254/ is refused, not followed. Hops are capped.
 */
final class GuardedHttpClient
{
    // === CONSTANTS ===
    private const DEFAULT_MAX_REDIRECTS = 5;

    private const DEFAULT_MAX_BYTES = 3_145_728;   // 3 MiB

    private const DEFAULT_TIMEOUT = 15;

    public function __construct(
        private readonly SingleHopTransport $transport,
        private readonly HostResolver $resolver,
    ) {}

    /**
     * Guarded GET. Returns the terminal (post-redirect) response.
     *
     * @param  array<string,string>  $headers
     */
    public function get(string $url, array $headers = [], ?int $maxBytes = null, ?int $timeout = null, ?int $maxRedirects = null): GuardedResponse
    {
        return $this->run('GET', $url, $headers, null, $maxBytes, $timeout, $maxRedirects);
    }

    /**
     * Guarded POST with a JSON body to a TRUSTED, operator-configured destination
     * (the render sidecar). Still PINNED + STREAM-CAPPED + no-redirect, but the
     * private-IP REJECTION is skipped because the sidecar legitimately lives on the
     * internal network. This path is NEVER used for a merchant-supplied URL.
     *
     * @param  array<string,string>  $headers
     */
    public function postJsonInternal(string $url, string $jsonBody, array $headers = [], ?int $maxBytes = null, ?int $timeout = null): GuardedResponse
    {
        return $this->run('POST', $url, $headers, $jsonBody, $maxBytes, $timeout, 0, trustInternal: true);
    }

    /**
     * @param  array<string,string>  $headers
     */
    private function run(
        string $method,
        string $url,
        array $headers,
        ?string $jsonBody,
        ?int $maxBytes,
        ?int $timeout,
        ?int $maxRedirects,
        bool $trustInternal = false,
    ): GuardedResponse {
        $maxBytes ??= self::DEFAULT_MAX_BYTES;
        $timeout ??= self::DEFAULT_TIMEOUT;
        $maxRedirects ??= self::DEFAULT_MAX_REDIRECTS;

        $current = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            // Re-guard EVERY hop: the input URL and each redirect target alike.
            // A trusted internal destination still resolves + pins, only skipping
            // the public-IP rejection (it is operator config, not attacker input).
            $pinnedIps = $trustInternal
                ? UrlGuard::resolvePinned($current, $this->resolver)
                : UrlGuard::resolveAndValidate($current, $this->resolver);

            $response = $this->transport->send(
                $method,
                $current,
                $headers,
                $pinnedIps,
                $maxBytes,
                $timeout,
                $method === 'POST' ? $jsonBody : null,
            );

            if (! $response->isRedirect()) {
                return new GuardedResponse(
                    status: $response->status,
                    body: $response->body,
                    finalUrl: $current,
                    truncated: $response->truncated,
                );
            }

            // Redirects do not carry the POST body forward; a redirected sidecar
            // call is a misconfiguration, refuse rather than silently re-POST.
            $current = $this->resolveLocation($current, (string) $response->location);
        }

        // Exceeded the hop budget → treat as an unreachable/looping target.
        throw FetchException::failed(ScanConstants::FAIL_HTTP_ERROR);
    }

    /** Resolve a possibly-relative Location against the current absolute URL. */
    private function resolveLocation(string $base, string $location): string
    {
        if ($location === '') {
            throw FetchException::failed(ScanConstants::FAIL_HTTP_ERROR);
        }

        // Absolute URL → use as-is (it will be re-guarded next loop).
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            throw FetchException::refused(ScanConstants::FAIL_INVALID_URL);
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = $parts['path'] ?? '/';
        $dir = substr($basePath, 0, strrpos($basePath, '/') ?: 0);

        return $origin.$dir.'/'.$location;
    }
}
