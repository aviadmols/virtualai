<?php

namespace Tests\Feature\Sites;

use App\Domain\Sites\ClubConfig;
use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2b — ClubConfig sanitize/resolve + the SiteSettingsService wiring.
 *
 * Proves the schema accepts valid input, applies defaults for absent keys, and REJECTS
 * every bad value (discount out of range/non-int, unknown surface, bad selector, too
 * many zones per surface) with a typed InvalidSiteSettingsException — nothing persisted.
 * resolve() always returns a complete, valid config. The service persists a valid patch
 * account-scoped.
 */
final class ClubConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitize_accepts_a_valid_config(): void
    {
        $clean = ClubConfig::sanitize([
            'enabled' => true,
            'discount_percent' => 15,
            'price_zones' => [
                'pdp' => ['.price', '#product-price'],
                'catalog' => ['.card .price'],
                'cart' => [],
            ],
        ]);

        $this->assertTrue($clean['enabled']);
        $this->assertSame(15, $clean['discount_percent']);
        $this->assertSame(['.price', '#product-price'], $clean['price_zones']['pdp']);
        $this->assertSame(['.card .price'], $clean['price_zones']['catalog']);
        $this->assertSame([], $clean['price_zones']['cart']);
    }

    public function test_sanitize_applies_defaults_for_absent_keys(): void
    {
        $clean = ClubConfig::sanitize([]);

        $this->assertSame(ClubConfig::DEFAULTS, $clean);
        $this->assertFalse($clean['enabled']);
        $this->assertSame(0, $clean['discount_percent']);
        $this->assertSame([], $clean['price_zones']['pdp']);
        $this->assertSame([], $clean['price_zones']['catalog']);
        $this->assertSame([], $clean['price_zones']['cart']);
    }

    public function test_sanitize_rejects_discount_above_100(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['discount_percent' => 101]);
    }

    public function test_sanitize_rejects_a_negative_discount(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['discount_percent' => -5]);
    }

    public function test_sanitize_rejects_a_non_integer_discount(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['discount_percent' => 12.5]);
    }

    public function test_sanitize_rejects_an_unknown_surface(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['price_zones' => ['checkout' => ['.total']]]);
    }

    public function test_sanitize_rejects_a_scriptable_selector(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        // Contains characters the CSS-selector allow-list forbids ({ } < ; are excluded).
        ClubConfig::sanitize(['price_zones' => ['pdp' => ['.price<script>']]]);
    }

    public function test_sanitize_rejects_too_many_zones_on_a_surface(): void
    {
        $tooMany = [];
        for ($i = 0; $i <= ClubConfig::ZONES_PER_SURFACE_MAX; $i++) {
            $tooMany[] = '.price-'.$i;
        }

        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['price_zones' => ['pdp' => $tooMany]]);
    }

    public function test_sanitize_drops_blank_selectors(): void
    {
        $clean = ClubConfig::sanitize(['price_zones' => ['pdp' => ['.price', '', '   ']]]);

        $this->assertSame(['.price'], $clean['price_zones']['pdp']);
    }

    public function test_resolve_returns_defaults_for_null_stored(): void
    {
        $this->assertSame(ClubConfig::DEFAULTS, ClubConfig::resolve(null));
    }

    public function test_resolve_merges_stored_over_defaults_and_completes_the_shape(): void
    {
        $resolved = ClubConfig::resolve(['enabled' => true, 'discount_percent' => 20]);

        $this->assertTrue($resolved['enabled']);
        $this->assertSame(20, $resolved['discount_percent']);
        // All three surfaces are present even when the stored value had none.
        $this->assertArrayHasKey('pdp', $resolved['price_zones']);
        $this->assertArrayHasKey('catalog', $resolved['price_zones']);
        $this->assertArrayHasKey('cart', $resolved['price_zones']);
    }

    // --- Banner behavior + timing ---

    public function test_sanitize_accepts_valid_banner_behavior(): void
    {
        $clean = ClubConfig::sanitize([
            'banner_trigger' => ClubConfig::TRIGGER_DELAY,
            'banner_delay_seconds' => 5,
            'banner_scroll_percent' => 40,
            'banner_position' => ClubConfig::POSITION_TOP_START,
            'banner_dismiss_days' => 14,
        ]);

        $this->assertSame(ClubConfig::TRIGGER_DELAY, $clean['banner_trigger']);
        $this->assertSame(5, $clean['banner_delay_seconds']);
        $this->assertSame(40, $clean['banner_scroll_percent']);
        $this->assertSame(ClubConfig::POSITION_TOP_START, $clean['banner_position']);
        $this->assertSame(14, $clean['banner_dismiss_days']);
    }

    public function test_defaults_include_the_banner_behavior_shape(): void
    {
        $clean = ClubConfig::sanitize([]);

        $this->assertSame(ClubConfig::TRIGGER_IMMEDIATE, $clean['banner_trigger']);
        $this->assertSame(ClubConfig::POSITION_BOTTOM_END, $clean['banner_position']);
        $this->assertIsInt($clean['banner_delay_seconds']);
        $this->assertIsInt($clean['banner_scroll_percent']);
        $this->assertSame(7, $clean['banner_dismiss_days']);
    }

    public function test_sanitize_rejects_an_unknown_trigger(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['banner_trigger' => 'sideways']);
    }

    public function test_sanitize_rejects_an_unknown_position(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['banner_position' => 'middle']);
    }

    public function test_sanitize_rejects_a_delay_above_the_max(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['banner_delay_seconds' => ClubConfig::DELAY_SECONDS_MAX + 1]);
    }

    public function test_sanitize_rejects_a_non_integer_delay(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['banner_delay_seconds' => '5']);
    }

    public function test_sanitize_rejects_a_scroll_percent_below_the_min(): void
    {
        // SCROLL_PERCENT_MIN is 1 — 0 is out of range.
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['banner_scroll_percent' => 0]);
    }

    public function test_sanitize_rejects_dismiss_days_out_of_range(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        ClubConfig::sanitize(['banner_dismiss_days' => ClubConfig::DISMISS_DAYS_MAX + 1]);
    }

    public function test_resolve_completes_the_banner_behavior_and_ignores_a_corrupted_enum(): void
    {
        // A corrupted stored enum + out-of-range int must fall back to the locked defaults
        // (resolve is lenient — a bad stored value can never reach the widget).
        $resolved = ClubConfig::resolve([
            'banner_trigger' => 'garbage',
            'banner_position' => 'nowhere',
            'banner_delay_seconds' => 9999,
            'banner_scroll_percent' => 30,
        ]);

        $this->assertSame(ClubConfig::TRIGGER_IMMEDIATE, $resolved['banner_trigger']);
        $this->assertSame(ClubConfig::POSITION_BOTTOM_END, $resolved['banner_position']);
        $this->assertSame(ClubConfig::DEFAULTS['banner_delay_seconds'], $resolved['banner_delay_seconds']);
        $this->assertSame(30, $resolved['banner_scroll_percent']); // a valid stored int is kept
    }

    public function test_the_settings_service_persists_a_valid_club_config(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($site) {
            app(SiteSettingsService::class)->update($site, [
                SiteSettingsService::KEY_CLUB_CONFIG => [
                    'enabled' => true,
                    'discount_percent' => 10,
                    'price_zones' => ['pdp' => ['.price']],
                ],
            ]);
        });

        $fresh = Tenant::run($account, fn () => Site::query()->findOrFail($site->getKey()));
        $this->assertTrue($fresh->club_config['enabled']);
        $this->assertSame(10, $fresh->club_config['discount_percent']);
        $this->assertSame(['.price'], $fresh->club_config['price_zones']['pdp']);
    }

    public function test_the_settings_service_rejects_a_bad_club_config_and_persists_nothing(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        try {
            Tenant::run($account, function () use ($site) {
                app(SiteSettingsService::class)->update($site, [
                    SiteSettingsService::KEY_CLUB_CONFIG => ['discount_percent' => 500],
                ]);
            });
            $this->fail('expected InvalidSiteSettingsException');
        } catch (InvalidSiteSettingsException $e) {
            $this->assertSame(InvalidSiteSettingsException::REASON_INVALID_CLUB_CONFIG, $e->reason);
        }

        $fresh = Tenant::run($account, fn () => Site::query()->findOrFail($site->getKey()));
        $this->assertNull($fresh->club_config);   // nothing written
    }
}
