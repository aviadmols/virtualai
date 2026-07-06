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
