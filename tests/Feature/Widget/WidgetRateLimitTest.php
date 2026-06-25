<?php

namespace Tests\Feature\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The per-account + per-site request-rate limiter (WidgetRateLimit): exceeding a cap
 * returns a TYPED 429 with Retry-After, never a 500. The numbers come from config
 * (railway-infra owns them); the limiter reads them.
 */
final class WidgetRateLimitTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
        RateLimiter::clear('widget_req_site');
    }

    public function test_exceeding_the_per_site_cap_returns_a_typed_429(): void
    {
        // Tiny cap so the test trips it deterministically.
        config()->set('widget.rate.site_rpm', 3);
        config()->set('widget.rate.account_rpm', 1000);
        $ctx = $this->makeSiteContext();

        $headers = $this->widgetHeaders($ctx['site'], $ctx['origin']);

        // The first 3 pass; the 4th is throttled.
        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($headers)->getJson('/widget/v1/bootstrap')->assertOk();
        }

        $throttled = $this->withHeaders($headers)->getJson('/widget/v1/bootstrap');

        $throttled->assertStatus(429)
            ->assertJson(['ok' => false, 'blocked' => true, 'reason' => 'rate_limited']);
        $this->assertNotEmpty($throttled->headers->get('Retry-After'));
        $this->assertGreaterThanOrEqual(1, (int) $throttled->json('retry_after'));
    }

    public function test_exceeding_the_per_account_cap_returns_a_typed_429(): void
    {
        config()->set('widget.rate.site_rpm', 1000);
        config()->set('widget.rate.account_rpm', 2);
        $ctx = $this->makeSiteContext();
        $headers = $this->widgetHeaders($ctx['site'], $ctx['origin']);

        $this->withHeaders($headers)->getJson('/widget/v1/bootstrap')->assertOk();
        $this->withHeaders($headers)->getJson('/widget/v1/bootstrap')->assertOk();

        $this->withHeaders($headers)->getJson('/widget/v1/bootstrap')
            ->assertStatus(429)
            ->assertJson(['reason' => 'rate_limited']);
    }

    public function test_one_sites_spike_does_not_throttle_another_site(): void
    {
        config()->set('widget.rate.site_rpm', 2);
        config()->set('widget.rate.account_rpm', 1000);

        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');

        // Burn site A's bucket.
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))->getJson('/widget/v1/bootstrap')->assertOk();
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))->getJson('/widget/v1/bootstrap')->assertOk();
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))->getJson('/widget/v1/bootstrap')->assertStatus(429);

        // Site B (different account + site bucket) is unaffected.
        $this->withHeaders($this->widgetHeaders($b['site'], $b['origin']))->getJson('/widget/v1/bootstrap')->assertOk();
    }
}
