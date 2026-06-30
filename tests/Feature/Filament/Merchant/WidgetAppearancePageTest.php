<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Sites\WidgetAppearance;
use App\Filament\Merchant\Pages\WidgetAppearanceSettings;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merchant "Widget appearance" page — renders and persists the per-site look.
 */
class WidgetAppearancePageTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();
        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->account)->create());
    }

    public function test_page_renders_for_the_owner(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(WidgetAppearanceSettings::class)->assertOk();
        });
    }

    public function test_saving_persists_the_appearance_to_the_site(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(WidgetAppearanceSettings::class)
                ->assertOk()
                ->fillForm([
                    WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_FIXED_BR,
                    WidgetAppearance::KEY_LABEL => 'Try it on me',
                    WidgetAppearance::KEY_BUTTON_BG => '#ff0000',
                    WidgetAppearance::KEY_BUTTON_TEXT => '#ffffff',
                    WidgetAppearance::KEY_POPUP_THEME => WidgetAppearance::THEME_DARK,
                    WidgetAppearance::KEY_POPUP_ACCENT => '#000000',
                ])
                ->call('save')
                ->assertHasNoFormErrors();
        });

        $stored = Tenant::run($this->account->id, fn () => Site::query()->find($this->site->id)->widget_appearance);

        $this->assertSame(WidgetAppearance::PLACEMENT_FIXED_BR, $stored[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('Try it on me', $stored[WidgetAppearance::KEY_LABEL]);
        $this->assertSame(WidgetAppearance::THEME_DARK, $stored[WidgetAppearance::KEY_POPUP_THEME]);
    }
}
