<?php

namespace App\Domain\Scan\Fetch;

/**
 * SingleHopTransport — the seam that performs ONE pinned, byte-capped HTTP hop.
 *
 * It does NOT follow redirects (the GuardedHttpClient does that manually, re-running
 * the SSRF guard on each Location) and it does NOT decide policy — it just sends the
 * request, pinned to the already-validated $pinnedIps, and streams the body through
 * a BoundedSink so the cap fires mid-stream.
 *
 * Behind an interface so a test can drive redirect chains and oversize streams with
 * NO network and NO real DNS, while production rides Guzzle/curl with CURLOPT_RESOLVE
 * (the pin) + CURLOPT_WRITEFUNCTION (the mid-stream cap).
 */
interface SingleHopTransport
{
    /**
     * @param  string  $method  'GET' or 'POST'.
     * @param  array<string,string>  $headers
     * @param  array<int,string>  $pinnedIps  the validated IPs the host MUST connect to.
     * @param  int  $maxBytes  the streaming body ceiling.
     * @param  int  $timeout  seconds.
     * @param  string|null  $jsonBody  raw JSON body for a POST, else null.
     *
     * @throws FetchException on a transport/timeout error.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        array $pinnedIps,
        int $maxBytes,
        int $timeout,
        ?string $jsonBody = null,
    ): TransportResponse;
}
