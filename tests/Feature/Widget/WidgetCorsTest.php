<?php

namespace Tests\Feature\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widget CORS: the browser must be able to make the cross-origin call from a merchant
 * store. Two pieces — the OPTIONS preflight is answered with CORS (WidgetCorsPreflight,
 * which Laravel's synthetic auto-OPTIONS would otherwise skip), and a request from the
 * site's OWN domain origin is allowed even before allowed_origins is filled.
 */
final class WidgetCorsTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_preflight_options_returns_cors_echoing_the_origin(): void
    {
        $response = $this->call('OPTIONS', '/widget/v1/bootstrap', [], [], [], [
            'HTTP_ORIGIN' => 'https://shop.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-tray-site-key',
        ]);

        $response->assertNoContent();
        $this->assertSame('https://shop.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotNull($response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_a_request_from_the_sites_own_domain_origin_is_allowed(): void
    {
        // allowed_origins is set to a DIFFERENT origin; the request comes from the site's
        // own domain origin — allowed via the domain fallback, not the allow-list.
        $ctx = $this->makeSiteContext(['domain' => 'https://mystore.test/']);
        $domainOrigin = 'https://mystore.test';

        $this->assertNotContains($domainOrigin, $ctx['site']->allowed_origins);

        $response = $this->withHeaders([
            'X-Tray-Site-Key' => $ctx['site']->site_key,
            'Origin' => $domainOrigin,
            'Accept' => 'application/json',
        ])->getJson('/widget/v1/bootstrap');

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($domainOrigin, $response->headers->get('Access-Control-Allow-Origin'));
    }
}
