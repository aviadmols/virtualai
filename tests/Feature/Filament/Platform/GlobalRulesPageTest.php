<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Pages\GlobalRules;
use App\Models\PlatformDirective;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Global Rules page — the Super-Admin editor for cross-site directives. Proves it renders, saves a
 * surface's rules + active flag, and bumps the version ONLY on a meaningful change (an idle save
 * must not churn the generation idempotency keys; a real edit must, so future images re-generate).
 */
class GlobalRulesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_page_renders(): void
    {
        Livewire::test(GlobalRules::class)->assertOk();
    }

    public function test_saving_stores_the_rules_and_bumps_version_only_on_a_real_change(): void
    {
        Livewire::test(GlobalRules::class)
            ->fillForm([
                'image_studio_rules' => 'Pure white background.',
                'image_studio_active' => true,
                'try_on_rules' => '',
                'try_on_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $img = PlatformDirective::query()->where('surface', PlatformDirective::SURFACE_IMAGE_STUDIO)->firstOrFail();
        $this->assertSame('Pure white background.', $img->rules);
        $this->assertTrue($img->is_active);
        $this->assertSame(1, $img->version);

        // An idle save (the page re-mounts from the stored values) must NOT bump the version.
        Livewire::test(GlobalRules::class)->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, $img->fresh()->version);

        // A real edit bumps the version → future generations re-render.
        Livewire::test(GlobalRules::class)
            ->fillForm(['image_studio_rules' => 'Pure white background. No shadows.', 'image_studio_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $img->fresh()->version);
        $this->assertSame('Pure white background. No shadows.', $img->fresh()->rules);
    }
}
