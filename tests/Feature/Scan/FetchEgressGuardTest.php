<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\BoundedSink;
use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Fetch\GuardedHttpClient;
use App\Domain\Scan\Fetch\HostResolver;
use App\Domain\Scan\Fetch\IpNormaliser;
use App\Domain\Scan\Fetch\SingleHopTransport;
use App\Domain\Scan\Fetch\TransportResponse;
use App\Domain\Scan\Fetch\UrlGuard;
use App\Domain\Scan\ScanConstants;
use Tests\TestCase;

/**
 * SSRF / egress guard — the BLOCKING Phase-4 findings, each with a red-when-removed
 * assertion. All network-free via two seams: a HostResolver fake that returns a
 * chosen IP for a fake host (no DNS), and a SingleHopTransport fake that emits
 * scripted responses (redirect chains, oversize streams) with no real socket.
 *
 * Covered vectors:
 *   #1 host that RESOLVES to 127.0.0.1 / 169.254.169.254 → refused
 *   #1 octal/obfuscated literal 0177.0.0.1 → refused
 *   #2 302 → internal IP redirect → refused (re-guarded per hop)
 *   #3 oversize body capped MID-STREAM (BoundedSink never buffers the whole body)
 */
class FetchEgressGuardTest extends TestCase
{
    // === FAKE SEAMS ===

    /** A resolver returning a fixed IP map per host (no real DNS lookup). */
    private function resolverReturning(array $map): HostResolver
    {
        return new class($map) implements HostResolver
        {
            public function __construct(private readonly array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        };
    }

    /** A transport that replays scripted responses (one per call), no network. */
    private function transportReplaying(TransportResponse ...$responses): SingleHopTransport
    {
        return new class($responses) implements SingleHopTransport
        {
            private int $i = 0;

            /** @var array<int,string> the URLs it was actually asked to send to. */
            public array $sentUrls = [];

            public function __construct(private readonly array $responses) {}

            public function send(string $method, string $url, array $headers, array $pinnedIps, int $maxBytes, int $timeout, ?string $jsonBody = null): TransportResponse
            {
                $this->sentUrls[] = $url;

                return $this->responses[$this->i++] ?? $this->responses[array_key_last($this->responses)];
            }
        };
    }

    // === #1 — host resolves to a private / metadata IP ===

    public function test_host_that_resolves_to_loopback_is_refused(): void
    {
        $resolver = $this->resolverReturning(['evil.example.com' => ['127.0.0.1']]);

        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/valid public product URL/');

        // GUARD UNDER TEST: UrlGuard::resolveAndValidate rejects the resolved IP.
        // RED-WHEN-REMOVED: delete the `! IpNormaliser::isPublic($ip)` throw in
        // UrlGuard::resolveAndValidate (the foreach loop) and this passes through.
        UrlGuard::resolveAndValidate('https://evil.example.com/x', $resolver);
    }

    public function test_host_that_resolves_to_cloud_metadata_is_refused(): void
    {
        $resolver = $this->resolverReturning(['metadata-rebind.example.com' => ['169.254.169.254']]);

        try {
            UrlGuard::resolveAndValidate('https://metadata-rebind.example.com/latest/meta-data', $resolver);
            $this->fail('Expected the metadata-resolving host to be refused.');
        } catch (FetchException $e) {
            $this->assertSame(ScanConstants::FAIL_INVALID_URL, $e->reason);
            $this->assertTrue($e->suggestManual);
        }
    }

    public function test_octal_obfuscated_ip_literal_is_refused(): void
    {
        // No resolver needed: the octal literal normalises to 127.0.0.1 in the
        // string layer. RED-WHEN-REMOVED: remove the IpNormaliser::normalise()
        // branch (or its isPublic() check) in UrlGuard::isPublicHttpUrl.
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://0177.0.0.1/x'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://0x7f.0.0.1/x'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://2130706433/x'));

        $resolver = $this->resolverReturning([]); // unused for a literal
        $this->expectException(FetchException::class);
        UrlGuard::resolveAndValidate('http://0177.0.0.1/x', $resolver);
    }

    public function test_unresolvable_host_is_refused(): void
    {
        $resolver = $this->resolverReturning([]); // resolves to nothing

        $this->expectException(FetchException::class);
        UrlGuard::resolveAndValidate('https://does-not-resolve.example.com/x', $resolver);
    }

    // === #2 — redirect to an internal IP is re-guarded ===

    public function test_redirect_to_internal_ip_is_refused_per_hop(): void
    {
        // hop 1: public host 302s to an internal-IP URL; the client must re-guard
        // the Location and refuse, never reaching a second transport send.
        $transport = $this->transportReplaying(
            new TransportResponse(302, '', 'http://169.254.169.254/latest/', false),
            new TransportResponse(200, 'SHOULD NEVER BE REACHED', null, false),
        );
        $resolver = $this->resolverReturning([
            'public.example.com' => ['93.184.216.34'],
            // 169.254.169.254 is a literal → normalised, never resolved here.
        ]);

        $client = new GuardedHttpClient($transport, $resolver);

        try {
            $client->get('https://public.example.com/p');
            $this->fail('Expected the internal-IP redirect to be refused.');
        } catch (FetchException $e) {
            // RED-WHEN-REMOVED: in GuardedHttpClient::run, move the
            // resolveAndValidate() call OUT of the for-loop (guard only hop 0).
            // Then the 169.254 hop is followed and this throw never fires.
            $this->assertSame(ScanConstants::FAIL_INVALID_URL, $e->reason);
        }

        // The second (internal) hop must never have been sent.
        $this->assertCount(1, $transport->sentUrls);
        $this->assertSame('https://public.example.com/p', $transport->sentUrls[0]);
    }

    public function test_redirect_to_a_public_host_is_followed(): void
    {
        $transport = $this->transportReplaying(
            new TransportResponse(301, '', 'https://cdn.example.com/final', false),
            new TransportResponse(200, 'FINAL BODY', null, false),
        );
        $resolver = $this->resolverReturning([
            'shop.example.com' => ['93.184.216.34'],
            'cdn.example.com' => ['93.184.216.35'],
        ]);

        $response = (new GuardedHttpClient($transport, $resolver))->get('https://shop.example.com/p');

        $this->assertSame(200, $response->status);
        $this->assertSame('FINAL BODY', $response->body);
        $this->assertSame('https://cdn.example.com/final', $response->finalUrl);
        $this->assertCount(2, $transport->sentUrls);
    }

    // === #3 — byte cap fires MID-STREAM ===

    public function test_bounded_sink_aborts_when_a_chunk_overshoots_the_cap(): void
    {
        // A SINGLE chunk (1500 bytes) overshoots the 1000-byte cap. The sink must
        // accept exactly the budget and signal abort — proving it never lets the
        // over-budget body land in the buffer.
        $sink = new BoundedSink(1000);

        $written = $sink->write(str_repeat('x', 1500));

        // RED-WHEN-REMOVED: in BoundedSink::write, replace the crossing-the-ceiling
        // branch `if (strlen($chunk) > $remaining) { $this->buffer .= substr(...);
        // ...; return 0; }` with `$this->buffer .= $chunk; return strlen($chunk);`.
        // Then $written === 1500 and body() is 1500 bytes — the cap is breached.
        $this->assertSame(0, $written, 'An over-budget chunk must signal abort (curl: 0 != strlen).');
        $this->assertTrue($sink->exceeded());
        $this->assertSame(1000, strlen($sink->body()), 'Body is capped to exactly MAX_BYTES, never the full chunk.');
    }

    public function test_bounded_sink_aborts_mid_stream_without_full_buffering(): void
    {
        $max = 1000;
        $sink = new BoundedSink($max);

        // Emit chunks of 300 bytes (unaligned to the cap). 1000/300 → the 4th chunk
        // crosses the ceiling; the abort must fire there, before the body is whole.
        $chunksConsumed = 0;
        $aborted = false;
        for ($i = 0; $i < 10; $i++) {
            $written = $sink->write(str_repeat('x', 300));
            $chunksConsumed++;

            if ($written !== 300) {        // curl contract: != strlen ⇒ abort
                $aborted = true;
                break;
            }
        }

        $this->assertTrue($aborted, 'The sink must abort once the cap is crossed.');
        $this->assertTrue($sink->exceeded());
        $this->assertSame($max, strlen($sink->body()), 'Body is capped to exactly MAX_BYTES.');
        $this->assertLessThanOrEqual(4, $chunksConsumed, 'Abort must fire on the crossing chunk, mid-stream.');
    }

    public function test_oversize_body_through_guarded_client_is_truncated_not_fully_buffered(): void
    {
        // A transport that reports a truncated, capped body (as the production
        // streaming sink would after aborting mid-stream).
        $cap = ScanConstants::EGRESS_MAX_BYTES;
        $transport = $this->transportReplaying(
            new TransportResponse(200, str_repeat('a', $cap), null, truncated: true),
        );
        $resolver = $this->resolverReturning(['shop.example.com' => ['93.184.216.34']]);

        $response = (new GuardedHttpClient($transport, $resolver))->get('https://shop.example.com/p');

        $this->assertTrue($response->truncated);
        $this->assertSame($cap, strlen($response->body));
    }

    // === IpNormaliser unit coverage (the range math the guard relies on) ===

    public function test_ip_normaliser_blocks_every_private_and_reserved_range(): void
    {
        foreach (['10.0.0.5', '172.16.5.5', '192.168.1.1', '127.0.0.1', '0.0.0.0',
            '169.254.169.254', '100.64.0.1', '::1', 'fc00::1', 'fe80::1',
            '::ffff:127.0.0.1', '::ffff:10.0.0.1'] as $ip) {
            $this->assertFalse(IpNormaliser::isPublic($ip), $ip.' must be blocked');
        }

        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(IpNormaliser::isPublic($ip), $ip.' must be public');
        }
    }
}
