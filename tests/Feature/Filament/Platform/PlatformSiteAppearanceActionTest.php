<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Sites\WidgetAppearance;
use App\Filament\Platform\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Sites "Widget appearance" action — a super-admin sets a site's button
 * placement + look from the platform panel, cross-account, persisted through the
 * audited PlatformSiteWriter and validated by WidgetAppearance::sanitize.
 */
class PlatformSiteAppearanceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_super_admin_sets_widget_appearance_for_a_site(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Livewire::test(ListSites::class)
            ->callTableAction('appearance', $site, data: [
                WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_FIXED_BR,
                WidgetAppearance::KEY_LABEL => 'Try it on',
                WidgetAppearance::KEY_BUTTON_BG => '#ff0000',
                WidgetAppearance::KEY_BUTTON_TEXT => '#ffffff',
                WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
                WidgetAppearance::KEY_POPUP_ACCENT => '#000000',
            ])
            ->assertHasNoTableActionErrors();

        $stored = Tenant::run($account, fn () => Site::query()->find($site->id)->widget_appearance);

        $this->assertSame(WidgetAppearance::PLACEMENT_FIXED_BR, $stored[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('Try it on', $stored[WidgetAppearance::KEY_LABEL]);
        $this->assertSame('#ff0000', $stored[WidgetAppearance::KEY_BUTTON_BG]);
        $this->assertSame(WidgetAppearance::THEME_DARK, $stored[WidgetAppearance::KEY_POPUP_THEME]);
    }

    public function test_a_custom_placement_submission_never_500s_and_is_not_persisted(): void
    {
        // 'custom' needs the merchant's visual picker and is NOT a preset option here. Submitting
        // it (with no anchor) must NOT throw an uncaught InvalidSiteSettingsException (the old 500)
        // — the action is guarded — and must never persist an invalid custom placement.
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Livewire::test(ListSites::class)
            ->callTableAction('appearance', $site, data: [
                WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
                WidgetAppearance::KEY_LABEL => 'Try it on',
                WidgetAppearance::KEY_BUTTON_BG => '#ff0000',
                WidgetAppearance::KEY_BUTTON_TEXT => '#ffffff',
                WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
                WidgetAppearance::KEY_POPUP_ACCENT => '#000000',
            ]);

        // Reaching here means no exception bubbled (no 500). The invalid custom never persisted.
        $stored = (array) Tenant::run($account, fn () => Site::query()->find($site->id)->widget_appearance);
        $this->assertNotSame(WidgetAppearance::PLACEMENT_CUSTOM, $stored[WidgetAppearance::KEY_PLACEMENT] ?? null);
    }

    public function test_a_site_with_custom_placement_opens_and_can_be_set_to_a_preset(): void
    {
        // A merchant may have set 'custom' via the visual picker. The platform modal must open it
        // (fillForm coerces custom -> a safe preset for display) and let the admin save a preset.
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($site): void {
            $site->widget_appearance = WidgetAppearance::sanitize([
                WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
                WidgetAppearance::KEY_CUSTOM_ANCHOR => '#add-to-cart',
                WidgetAppearance::KEY_CUSTOM_POSITION => WidgetAppearance::POSITION_AFTER,
                WidgetAppearance::KEY_LABEL => 'Try it on',
                WidgetAppearance::KEY_BUTTON_BG => '#ff0000',
                WidgetAppearance::KEY_BUTTON_TEXT => '#ffffff',
                WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
                WidgetAppearance::KEY_POPUP_ACCENT => '#000000',
            ]);
            $site->save();
        });

        Livewire::test(ListSites::class)
            ->callTableAction('appearance', $site, data: [
                WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_AFTER_ATC,
                WidgetAppearance::KEY_LABEL => 'Try it on',
                WidgetAppearance::KEY_BUTTON_BG => '#ff0000',
                WidgetAppearance::KEY_BUTTON_TEXT => '#ffffff',
                WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
                WidgetAppearance::KEY_POPUP_ACCENT => '#000000',
            ])
            ->assertHasNoTableActionErrors();

        $stored = Tenant::run($account, fn () => Site::query()->find($site->id)->widget_appearance);
        $this->assertSame(WidgetAppearance::PLACEMENT_AFTER_ATC, $stored[WidgetAppearance::KEY_PLACEMENT]);
    }
}
