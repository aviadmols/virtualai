<?php

namespace Tests\Feature\Widget;

use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2b — the bootstrap `club` object (GET /widget/v1/bootstrap).
 *
 * Proves the bootstrap response carries the resolved per-site club config (enabled,
 * discount_percent, price_zones{pdp,catalog,cart}) plus THIS shopper's membership state
 * (member.verified from verified_at), and that a member of account A is not reported for
 * account B (isolation via the bound tenant).
 */
final class WidgetClubBootstrapTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    // === CONSTANTS ===
    private const ENDPOINT = '/widget/v1/bootstrap';

    private const ANON = 'anon_club_boot_1234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_bootstrap_returns_the_club_object_with_defaults_when_unconfigured(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson(self::ENDPOINT.'?anon_token='.self::ANON);

        $response->assertOk()->assertJson([
            'ok' => true,
            'club' => [
                'enabled' => false,
                'discount_percent' => 0,
                'price_zones' => ['pdp' => [], 'catalog' => [], 'cart' => []],
                'member' => ['verified' => false],
            ],
        ]);
    }

    public function test_bootstrap_returns_the_resolved_club_config(): void
    {
        $ctx = $this->makeSiteContext([
            'club_config' => [
                'enabled' => true,
                'discount_percent' => 12,
                'price_zones' => ['pdp' => ['.price'], 'catalog' => ['.card .price']],
                // Banner behavior/timing — the controller must carry every field to the widget.
                'banner_trigger' => 'delay',
                'banner_delay_seconds' => 8,
                'banner_scroll_percent' => 40,
                'banner_position' => 'top-start',
                'banner_dismiss_days' => 30,
            ],
        ]);

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson(self::ENDPOINT.'?anon_token='.self::ANON);

        // Assert the whole resolved club block — including the 5 banner_* keys — is emitted, so a
        // regression that drops any field from clubPayload() fails here (the controller seam is
        // otherwise untested: ClubConfig tests hit resolve() directly, the widget harness mocks it).
        $response->assertOk()->assertJson([
            'club' => [
                'enabled' => true,
                'discount_percent' => 12,
                'price_zones' => ['pdp' => ['.price'], 'catalog' => ['.card .price'], 'cart' => []],
                'banner_trigger' => 'delay',
                'banner_delay_seconds' => 8,
                'banner_scroll_percent' => 40,
                'banner_position' => 'top-start',
                'banner_dismiss_days' => 30,
                'member' => ['verified' => false],
            ],
        ]);
    }

    public function test_bootstrap_reports_a_verified_member(): void
    {
        $ctx = $this->makeSiteContext(['club_config' => ['enabled' => true, 'discount_percent' => 10]]);

        // Pre-create a verified end user for this token (a club member).
        Tenant::run($ctx['account'], function () use ($ctx) {
            \App\Models\EndUser::query()->create([
                'site_id' => $ctx['site']->getKey(),
                'anon_token' => self::ANON,
                'email' => 'member@example.com',
                'verified_at' => now(),
                'last_seen_at' => now(),
            ]);
        });

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson(self::ENDPOINT.'?anon_token='.self::ANON);

        $response->assertOk()->assertJson(['club' => ['member' => ['verified' => true]]]);
    }

    public function test_bootstrap_member_state_is_account_isolated(): void
    {
        $a = $this->makeSiteContext(['club_config' => ['enabled' => true]], 'https://a.example.com');
        $b = $this->makeSiteContext(['club_config' => ['enabled' => true]], 'https://b.example.com');

        // A verified member exists under account A for the shared anon token.
        Tenant::run($a['account'], function () use ($a) {
            \App\Models\EndUser::query()->create([
                'site_id' => $a['site']->getKey(),
                'anon_token' => self::ANON,
                'email' => 'a-member@example.com',
                'verified_at' => now(),
                'last_seen_at' => now(),
            ]);
        });

        // Booting account B's widget with the SAME token must NOT report a member — B has
        // its own (unverified) end user; A's membership is invisible across the tenant.
        $response = $this->withHeaders($this->widgetHeaders($b['site'], $b['origin']))
            ->getJson(self::ENDPOINT.'?anon_token='.self::ANON);

        $response->assertOk()->assertJson(['club' => ['member' => ['verified' => false]]]);
    }
}
