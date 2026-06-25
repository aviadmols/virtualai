<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * GuzzleSingleHopTransport — the production single-hop transport.
 *
 * Rides the Laravel HTTP client (Guzzle/curl) and applies the two egress controls
 * that only the real transport can enforce:
 *   - PIN: CURLOPT_RESOLVE forces the host's TCP connection to the already-validated
 *     IP, so DNS cannot re-resolve to a private address between check and connect.
 *   - STREAM-CAP: CURLOPT_WRITEFUNCTION feeds the body through a BoundedSink that
 *     aborts the transfer the instant MAX_BYTES is crossed — never a full buffer.
 *
 * Auto-redirects are DISABLED here; the GuardedHttpClient follows them manually and
 * re-guards each hop. Under Http::fake (tests) curl options are ignored, so the cap
 * is ALSO applied to the returned body as a backstop; the mid-stream guarantee is
 * proven separately by a fake transport driving BoundedSink directly.
 */
final class GuzzleSingleHopTransport implements SingleHopTransport
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function send(
        string $method,
        string $url,
        array $headers,
        array $pinnedIps,
        int $maxBytes,
        int $timeout,
        ?string $jsonBody = null,
    ): TransportResponse {
        $sink = new BoundedSink($maxBytes);

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->withOptions([
                    // Never auto-follow: the client re-guards every hop itself.
                    'allow_redirects' => false,
                    'curl' => $this->curlOptions($url, $pinnedIps, $sink),
                ])
                ->send($method, $url, $this->bodyOptions($jsonBody));
        } catch (ConnectionException) {
            throw FetchException::failed(ScanConstants::FAIL_TIMEOUT);
        }

        return $this->toTransportResponse($response, $sink, $maxBytes);
    }

    /**
     * curl: pin every host involved to the validated IP (CURLOPT_RESOLVE), and
     * stream the body through the BoundedSink (CURLOPT_WRITEFUNCTION) so the cap
     * fires mid-stream. Returning < bytes from the write callback aborts the curl
     * transfer.
     *
     * @param  array<int,string>  $pinnedIps
     * @return array<int,mixed>
     */
    private function curlOptions(string $url, array $pinnedIps, BoundedSink $sink): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        $resolve = [];
        foreach ($pinnedIps as $ip) {
            // host:port:ip — curl connects to $ip but keeps Host/SNI = $host.
            $resolve[] = $host.':'.$port.':'.$ip;
        }

        return [
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($sink): int {
                return $sink->write($chunk);
            },
        ];
    }

    /** @return array<string,mixed> */
    private function bodyOptions(?string $jsonBody): array
    {
        if ($jsonBody === null) {
            return [];
        }

        return ['body' => $jsonBody, 'headers' => ['Content-Type' => 'application/json']];
    }

    /**
     * Build the typed response. Prefer the streamed sink body; under a fake handler
     * (no curl write-callback) fall back to the buffered body but enforce the cap
     * on it too so an oversize fake body is still truncated.
     */
    private function toTransportResponse(Response $response, BoundedSink $sink, int $maxBytes): TransportResponse
    {
        $streamed = $sink->body();
        $truncated = $sink->exceeded();

        if ($streamed === '') {
            // Fake/non-curl handler path: cap the buffered body as a backstop.
            $buffered = $response->body();
            if (strlen($buffered) > $maxBytes) {
                $streamed = substr($buffered, 0, $maxBytes);
                $truncated = true;
            } else {
                $streamed = $buffered;
            }
        }

        $location = $response->header('Location');

        return new TransportResponse(
            status: $response->status(),
            body: $streamed,
            location: $location !== '' ? $location : null,
            truncated: $truncated,
        );
    }
}
