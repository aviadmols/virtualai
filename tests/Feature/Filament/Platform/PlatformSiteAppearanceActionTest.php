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
}
