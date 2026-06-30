<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Platform\PlatformSettings;
use App\Filament\Platform\Pages\Settings;
use App\Models\PlatformSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Settings page — render, save, and the write-only secret contract.
 *
 * Proves the page renders for a super-admin, a key entered in the form is stored (and
 * then resolves as the effective value), and a STORED secret is never preloaded into
 * the form/browser (write-only — the key never reaches the browser).
 */
class PlatformSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test(Settings::class)->assertOk();
    }

    public function test_saving_stores_the_openrouter_key(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['openrouter_api_key' => 'sk-or-entered-in-ui'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'sk-or-entered-in-ui',
            app(PlatformSettings::class)->resolve(PlatformSettings::OPENROUTER_API_KEY),
        );
    }

    public function test_a_stored_secret_is_write_only_never_preloaded(): void
    {
        PlatformSetting::create([
            'key' => PlatformSettings::OPENROUTER_API_KEY,
            'value' => 'sk-or-already-stored',
            'is_secret' => true,
        ]);

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertDontSee('sk-or-already-stored')
            ->assertFormSet(['openrouter_api_key' => null]);
    }

    public function test_blank_save_keeps_the_existing_value(): void
    {
        PlatformSetting::create([
            'key' => PlatformSettings::OPENROUTER_API_KEY,
            'value' => 'sk-or-keep-me',
            'is_secret' => true,
        ]);

        // Saving with the field left blank must NOT wipe the stored key.
        Livewire::test(Settings::class)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-or-keep-me', app(PlatformSettings::class)->resolve(PlatformSettings::OPENROUTER_API_KEY));
    }
}
