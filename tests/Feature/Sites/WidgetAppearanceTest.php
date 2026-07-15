<?php

namespace Tests\Feature\Sites;

use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Domain\Sites\StoreCategory;
use App\Domain\Sites\WidgetAppearance;
use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WidgetAppearance schema + its persistence through SiteSettingsService.
 *
 * Proves resolve() merges stored values over the defaults (so the widget always gets a
 * complete look), sanitize() rejects every bad value (enum/hex/label/theme), and the
 * settings service stores the sanitized full set under the one whitelisted column.
 */
class WidgetAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_returns_defaults_when_unset(): void
    {
        $this->assertSame(WidgetAppearance::DEFAULTS, WidgetAppearance::resolve(null));
    }

    public function test_resolve_merges_stored_over_defaults(): void
    {
        $resolved = WidgetAppearance::resolve([
            WidgetAppearance::KEY_LABEL => 'Try me',
            WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
        ]);

        $this->assertSame('Try me', $resolved[WidgetAppearance::KEY_LABEL]);
        $this->assertSame(WidgetAppearance::THEME_DARK, $resolved[WidgetAppearance::KEY_POPUP_THEME]);
        // Untouched keys keep their defaults.
        $this->assertSame(WidgetAppearance::PLACEMENT_AFTER_ATC, $resolved[WidgetAppearance::KEY_PLACEMENT]);
    }

    public function test_ask_height_and_consent_default_off(): void
    {
        $resolved = WidgetAppearance::resolve(null, StoreCategory::CLOTHING);
        $this->assertFalse($resolved[WidgetAppearance::KEY_ASK_HEIGHT]);
        $this->assertFalse($resolved[WidgetAppearance::KEY_ASK_CONSENT]);

        $this->assertFalse(WidgetAppearance::resolve(null, null)[WidgetAppearance::KEY_ASK_HEIGHT]);
        $this->assertFalse(WidgetAppearance::resolve(null, null)[WidgetAppearance::KEY_ASK_CONSENT]);
    }

    public function test_explicit_ask_height_and_consent_overrides_defaults(): void
    {
        $on = WidgetAppearance::resolve([
            WidgetAppearance::KEY_ASK_HEIGHT => true,
            WidgetAppearance::KEY_ASK_CONSENT => true,
        ], StoreCategory::JEWELRY);
        $this->assertTrue($on[WidgetAppearance::KEY_ASK_HEIGHT]);
        $this->assertTrue($on[WidgetAppearance::KEY_ASK_CONSENT]);

        $off = WidgetAppearance::resolve([
            WidgetAppearance::KEY_ASK_HEIGHT => false,
            WidgetAppearance::KEY_ASK_CONSENT => false,
        ], StoreCategory::CLOTHING);
        $this->assertFalse($off[WidgetAppearance::KEY_ASK_HEIGHT]);
        $this->assertFalse($off[WidgetAppearance::KEY_ASK_CONSENT]);
    }

    public function test_sanitize_accepts_valid_values_and_normalises_hex(): void
    {
        $clean = WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_FIXED_BR,
            WidgetAppearance::KEY_LABEL => '  Try on  ',
            WidgetAppearance::KEY_BUTTON_BG => '#FF0000',
            WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
        ]);

        $this->assertSame(WidgetAppearance::PLACEMENT_FIXED_BR, $clean[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('Try on', $clean[WidgetAppearance::KEY_LABEL]);   // trimmed
        $this->assertSame('#ff0000', $clean[WidgetAppearance::KEY_BUTTON_BG]); // lowercased
        $this->assertSame(WidgetAppearance::THEME_DARK, $clean[WidgetAppearance::KEY_POPUP_THEME]);
    }

    public static function badValueProvider(): array
    {
        return [
            'placement' => [[WidgetAppearance::KEY_PLACEMENT => 'top_left']],
            'hex' => [[WidgetAppearance::KEY_BUTTON_BG => 'red']],
            'short-hex' => [[WidgetAppearance::KEY_POPUP_ACCENT => '#fff']],
            'empty-label' => [[WidgetAppearance::KEY_LABEL => '   ']],
            'theme' => [[WidgetAppearance::KEY_POPUP_THEME => 'rainbow']],
        ];
    }

    #[DataProvider('badValueProvider')]
    public function test_sanitize_rejects_bad_values(array $input): void
    {
        $this->expectException(InvalidSiteSettingsException::class);
        WidgetAppearance::sanitize($input);
    }

    public function test_settings_service_persists_sanitized_appearance(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, fn () => app(SiteSettingsService::class)->update($site, [
            SiteSettingsService::KEY_WIDGET_APPEARANCE => [
                WidgetAppearance::KEY_LABEL => 'Wear it',
                WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
            ],
        ]));

        $stored = Tenant::run($account, fn () => Site::query()->find($site->id)->widget_appearance);

        $this->assertSame('Wear it', $stored[WidgetAppearance::KEY_LABEL]);
        $this->assertSame(WidgetAppearance::THEME_DARK, $stored[WidgetAppearance::KEY_POPUP_THEME]);
        // sanitize() fills the rest with defaults — a complete, valid object is stored.
        $this->assertSame(WidgetAppearance::PLACEMENT_AFTER_ATC, $stored[WidgetAppearance::KEY_PLACEMENT]);
    }

    public function test_settings_service_rejects_a_bad_appearance_value(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $this->expectException(InvalidSiteSettingsException::class);

        Tenant::run($account, fn () => app(SiteSettingsService::class)->update($site, [
            SiteSettingsService::KEY_WIDGET_APPEARANCE => [WidgetAppearance::KEY_BUTTON_BG => 'not-a-hex'],
        ]));
    }
}
