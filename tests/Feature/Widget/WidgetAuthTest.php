<?php

namespace Tests\Feature\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The widget-auth middleware (ResolveWidgetSite): site_key + Origin allow-list + optional
 * HMAC, all as TYPED JSON rejections (401/403), never a 500/HTML. And the load-bearing
 * invariant: no widget_secret / OpenRouter key ever appears in any response body.
 */
final class WidgetAuthTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_valid_site_key_and_allow_listed_origin_passes(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/bootstrap');

        $response->assertOk()->assertJson(['ok' => true]);
        // CORS reflects the single allow-listed origin (never a wildcard).
        $this->assertSame($ctx['origin'], $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_unknown_site_key_is_a_typed_401(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders([
            'X-Tray-Site-Key' => 'site_does_not_exist',
            'Origin' => $ctx['origin'],
            'Accept' => 'application/json',
        ])->getJson('/widget/v1/bootstrap');

        $response->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => ['code' => 'unknown_site']]);
    }

    public function test_missing_site_key_is_a_typed_401(): void
    {
        $this->makeSiteContext();

        $this->getJson('/widget/v1/bootstrap', ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => ['code' => 'unknown_site']]);
    }

    public function test_disallowed_origin_is_a_typed_403(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders([
            'X-Tray-Site-Key' => $ctx['site']->site_key,
            'Origin' => 'https://evil.attacker.example',
            'Accept' => 'application/json',
        ])->getJson('/widget/v1/bootstrap');

        $response->assertStatus(403)
            ->assertJson(['ok' => false, 'error' => ['code' => 'origin_not_allowed']]);
    }

    public function test_absent_origin_is_rejected(): void
    {
        $ctx = $this->makeSiteContext();

        // No Origin header at all -> never passes (a real browser fetch always sends one).
        $this->withHeaders([
            'X-Tray-Site-Key' => $ctx['site']->site_key,
            'Accept' => 'application/json',
        ])->getJson('/widget/v1/bootstrap')->assertStatus(403);
    }

    public function test_hmac_required_call_without_signature_is_a_typed_403(): void
    {
        config()->set('widget.hmac.enabled', true);
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/leads', [
                'full_name' => 'Dana Levi',
                'email' => 'dana@example.com',
                'anon_token' => 'anon_token_1234567890',
            ]);

        $response->assertStatus(403)
            ->assertJson(['ok' => false, 'error' => ['code' => 'signature_required']]);
    }

    public function test_hmac_required_call_with_bad_signature_is_a_typed_403(): void
    {
        config()->set('widget.hmac.enabled', true);
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']) + [
            'X-Tray-Signature' => 'deadbeef-not-a-real-hmac',
            'X-Tray-Timestamp' => (string) now()->getTimestamp(),
        ])->postJson('/widget/v1/leads', [
            'full_name' => 'Dana Levi',
            'email' => 'dana@example.com',
            'anon_token' => 'anon_token_1234567890',
        ]);

        $response->assertStatus(403)
            ->assertJson(['ok' => false, 'error' => ['code' => 'signature_invalid']]);
    }

    public function test_hmac_valid_signature_passes(): void
    {
        config()->set('widget.hmac.enabled', true);
        $ctx = $this->makeSiteContext();

        $timestamp = (string) now()->getTimestamp();
        $body = ['full_name' => 'Dana Levi', 'email' => 'dana@example.com', 'anon_token' => 'anon_token_1234567890'];
        $raw = json_encode($body);
        $signature = hash_hmac('sha256', $timestamp."\n".$raw, $ctx['site']->widget_secret);

        $response = $this->call(
            'POST',
            '/widget/v1/leads',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->widgetHeaders($ctx['site'], $ctx['origin']) + [
                'X-Tray-Signature' => $signature,
                'X-Tray-Timestamp' => $timestamp,
                'Content-Type' => 'application/json',
            ]),
            $raw,
        );

        $response->assertStatus(201)->assertJson(['ok' => true]);
    }

    public function test_no_secret_in_any_response_body(): void
    {
        $ctx = $this->makeSiteContext();
        $secret = $ctx['site']->widget_secret;

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/bootstrap?url='.urlencode('https://shop.example.com/p/red-sneaker').'&anon_token=anon_token_1234567890');

        $body = $response->getContent();
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('widget_secret', $body);
        $this->assertStringNotContainsString('sk-or-test', $body); // OpenRouter key never leaks
    }
}
