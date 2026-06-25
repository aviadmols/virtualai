<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Fetch\GuardedHttpClient;
use App\Domain\Scan\Fetch\GuzzleSingleHopTransport;
use App\Domain\Scan\Fetch\HeadlessPageFetcher;
use App\Domain\Scan\Fetch\HostResolver;
use App\Domain\Scan\Fetch\HttpPageFetcher;
use App\Domain\Scan\Fetch\PageFetcherManager;
use App\Domain\Scan\Fetch\RobotsPolicy;
use App\Domain\Scan\ScanConstants;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The HTTP-first / headless-fallback escalation, network mocked via Http::fake.
 * A server-rendered PDP returns from the HTTP path; a SPA shell escalates to the
 * headless sidecar (also faked). No real browser, no real fetch. Hosts resolve via
 * a public-IP HostResolver fake so the SSRF guard passes for the test origins.
 */
class PageFetcherManagerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/Scan/'.$name));
    }

    /** A resolver that maps every test host to a public IP (no real DNS). */
    private function publicResolver(): HostResolver
    {
        return new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34']; // example.com's public IP
            }
        };
    }

    private function manager(HttpFactory $http): PageFetcherManager
    {
        $client = new GuardedHttpClient(
            new GuzzleSingleHopTransport($http),
            $this->publicResolver(),
        );

        return new PageFetcherManager(
            new HttpPageFetcher($client, new RobotsPolicy($client)),
            new HeadlessPageFetcher($client),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.scraper.respect_robots', false);
        config()->set('services.scraper.user_agent', 'TrayOnBot/1.0');
    }

    public function test_server_rendered_pdp_returns_via_http_no_headless(): void
    {
        $http = new HttpFactory;
        $http->fake([
            'shop.example.com/products/x' => $http->response($this->fixture('shopify_pdp.html'), 200),
        ]);

        $result = $this->manager($http)->fetch('https://shop.example.com/products/x');

        $this->assertSame(ScanConstants::FETCH_VIA_HTTP, $result->fetchedVia);
        $this->assertStringContainsString('Merino Crew', $result->html);
    }

    public function test_spa_shell_escalates_to_headless_sidecar(): void
    {
        config()->set('services.scraper.render_enabled', true);
        config()->set('services.scraper.service_url', 'https://render.internal');
        config()->set('services.scraper.service_token', 'secret');

        $http = new HttpFactory;
        $http->fake([
            'shop.spa.com/p/1' => $http->response($this->fixture('spa_shell.html'), 200),
            'render.internal/render' => $http->response([
                'html' => $this->fixture('shopify_pdp.html'),
                'final_url' => 'https://shop.spa.com/p/1',
                'screenshot_base64' => base64_encode('fake-png-bytes'),
                'screenshot_mime' => 'image/png',
            ], 200),
        ]);

        $result = $this->manager($http)->fetch('https://shop.spa.com/p/1');

        $this->assertSame(ScanConstants::FETCH_VIA_HEADLESS, $result->fetchedVia);
        $this->assertTrue($result->hasScreenshot());
        $this->assertStringContainsString('Merino Crew', $result->html);
    }

    public function test_spa_shell_with_disabled_renderer_falls_back_to_http_body(): void
    {
        // Renderer disabled: a non-empty HTTP body is kept rather than failing.
        config()->set('services.scraper.render_enabled', false);

        $http = new HttpFactory;
        $http->fake([
            'shop.spa.com/p/2' => $http->response($this->fixture('spa_shell.html'), 200),
        ]);

        $result = $this->manager($http)->fetch('https://shop.spa.com/p/2');

        $this->assertSame(ScanConstants::FETCH_VIA_HTTP, $result->fetchedVia);
    }

    public function test_bot_challenge_body_fails_with_merchant_reason(): void
    {
        $http = new HttpFactory;
        $http->fake([
            'blocked.example.com/p' => $http->response('<html><body>Just a moment... cf-challenge</body></html>', 200),
        ]);

        try {
            $this->manager($http)->fetch('https://blocked.example.com/p');
            $this->fail('Expected a bot-block failure.');
        } catch (FetchException $e) {
            $this->assertSame(ScanConstants::FAIL_BOT_BLOCKED, $e->reason);
            $this->assertTrue($e->suggestManual);
        }
    }

    public function test_403_status_fails_as_bot_blocked(): void
    {
        $http = new HttpFactory;
        $http->fake([
            'forbidden.example.com/p' => $http->response('nope', 403),
        ]);

        try {
            $this->manager($http)->fetch('https://forbidden.example.com/p');
            $this->fail('Expected a block failure.');
        } catch (FetchException $e) {
            $this->assertSame(ScanConstants::FAIL_BOT_BLOCKED, $e->reason);
        }
    }
}
