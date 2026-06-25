<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Fetch\HostResolver;
use App\Domain\Scan\Fetch\RenderDecision;
use App\Domain\Scan\Fetch\UrlGuard;
use App\Domain\Scan\ScanConstants;
use Tests\TestCase;

/**
 * The fetch strategy decisions: SSRF guard, the headless-escalation heuristic, and
 * the typed merchant-facing refusals. Pure logic — no network.
 */
class FetchStrategyTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/Scan/'.$name));
    }

    // --- SSRF / URL guard ---

    public function test_public_https_url_is_fetchable(): void
    {
        $this->assertTrue(UrlGuard::isPublicHttpUrl('https://shop.example.com/products/x'));
    }

    public function test_localhost_and_private_ips_are_refused(): void
    {
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://localhost/x'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://127.0.0.1/x'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://10.0.0.5/x'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('ftp://example.com/x'));
        $this->assertFalse(UrlGuard::isPublicHttpUrl('file:///etc/passwd'));
    }

    public function test_assert_fetchable_throws_typed_refusal(): void
    {
        try {
            UrlGuard::assertFetchable('http://169.254.169.254/');
            $this->fail('Expected refusal.');
        } catch (FetchException $e) {
            $this->assertSame(ScanConstants::FAIL_INVALID_URL, $e->reason);
            $this->assertTrue($e->suggestManual);
        }
    }

    public function test_obfuscated_ip_literals_are_refused_by_string_guard(): void
    {
        // Octal / hex / dword / short forms all normalise to a private literal.
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://0177.0.0.1/x'));      // 127.0.0.1
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://0x7f.0.0.1/x'));      // 127.0.0.1
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://2130706433/x'));      // 127.0.0.1
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://127.1/x'));           // 127.0.0.1
        $this->assertFalse(UrlGuard::isPublicHttpUrl('http://metadata.google.internal/x'));
    }

    public function test_host_resolving_to_private_ip_is_refused(): void
    {
        // The string layer can't see this — only resolution catches it.
        $resolver = $this->resolver(['rebind.example.com' => ['10.0.0.5']]);

        $this->expectException(FetchException::class);
        UrlGuard::resolveAndValidate('https://rebind.example.com/x', $resolver);
    }

    public function test_host_resolving_only_to_public_ip_passes_and_returns_pin(): void
    {
        $resolver = $this->resolver(['shop.example.com' => ['93.184.216.34']]);

        $pinned = UrlGuard::resolveAndValidate('https://shop.example.com/p', $resolver);

        $this->assertSame(['93.184.216.34'], $pinned);
    }

    /** A HostResolver fake mapping host → IPs, no real DNS. */
    private function resolver(array $map): HostResolver
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

    // --- Headless-escalation heuristic ---

    public function test_server_rendered_pdp_looks_rendered_no_escalation(): void
    {
        $html = $this->fixture('shopify_pdp.html');

        $this->assertTrue(RenderDecision::looksRendered($html));
        $this->assertFalse(RenderDecision::shouldEscalate($html));
    }

    public function test_spa_shell_escalates_to_headless(): void
    {
        $html = $this->fixture('spa_shell.html');

        $this->assertFalse(RenderDecision::looksRendered($html));
        $this->assertTrue(RenderDecision::shouldEscalate($html));
    }

    public function test_merchant_facing_message_is_present_for_each_reason(): void
    {
        $e = FetchException::failed(ScanConstants::FAIL_BOT_BLOCKED);

        $this->assertStringContainsString('manually', $e->merchantMessage());
        $this->assertTrue($e->suggestManual);
    }
}
