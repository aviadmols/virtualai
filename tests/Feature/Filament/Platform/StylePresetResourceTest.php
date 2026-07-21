<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\StylePresetResource\Pages\CreateStylePreset;
use App\Filament\Platform\Resources\StylePresetResource\Pages\ListStylePresets;
use App\Models\AiOperation;
use App\Models\StylePreset;
use App\Models\User;
use App\Support\GlobalModels;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Style Presets library (super-admin). Phase 1: the model + resource CRUD + the
 * approved-for-surface scope + the global (non-tenant) allow-list membership.
 */
class StylePresetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_list_renders_for_a_super_admin(): void
    {
        Livewire::test(ListStylePresets::class)->assertOk();
    }

    public function test_creating_a_preset_persists_a_draft_with_no_sample_yet(): void
    {
        Livewire::test(CreateStylePreset::class)
            ->fillForm([
                'name' => 'Vintage film',
                'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
                'user_prompt' => 'A warm vintage film look. It is made of {{materials}}.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $preset = StylePreset::query()->where('name', 'Vintage film')->firstOrFail();
        $this->assertSame(StylePreset::STATUS_DRAFT, $preset->status);
        $this->assertSame(StylePreset::SAMPLE_PENDING, $preset->sample_status);
        $this->assertSame(StylePreset::SURFACE_TRY_ON, $preset->surface());
    }

    public function test_approved_for_operations_returns_only_approved_active_presets(): void
    {
        $approved = StylePreset::create([
            'name' => 'ok', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'x', 'status' => StylePreset::STATUS_APPROVED, 'is_active' => true,
        ]);
        StylePreset::create([ // draft -> excluded
            'name' => 'draft', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'x', 'status' => StylePreset::STATUS_DRAFT, 'is_active' => true,
        ]);
        StylePreset::create([ // approved but inactive -> excluded
            'name' => 'off', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'x', 'status' => StylePreset::STATUS_APPROVED, 'is_active' => false,
        ]);
        StylePreset::create([ // approved but other surface -> excluded
            'name' => 'banner', 'operation_key' => AiOperation::KEY_BANNER_GENERATION,
            'user_prompt' => 'x', 'status' => StylePreset::STATUS_APPROVED, 'is_active' => true,
        ]);

        $ids = StylePreset::query()
            ->approvedForOperations(StylePreset::SURFACE_OPERATIONS[StylePreset::SURFACE_TRY_ON])
            ->pluck('id')->all();

        $this->assertSame([$approved->id], $ids);
    }

    public function test_style_preset_is_a_registered_global_non_tenant_model(): void
    {
        // It holds no tenant data and only swaps a prompt, so it is deliberately NOT
        // BelongsToAccount — it MUST be on the audited global allow-list.
        $this->assertTrue(GlobalModels::isGlobal(StylePreset::class));
    }
}
