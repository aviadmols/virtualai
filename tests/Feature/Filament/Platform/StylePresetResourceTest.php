<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\StylePresetResource\Pages\CreateStylePreset;
use App\Filament\Platform\Resources\StylePresetResource\Pages\ListStylePresets;
use App\Jobs\GenerateStylePresetSampleJob;
use App\Models\AiOperation;
use App\Models\StylePreset;
use App\Models\User;
use App\Support\GlobalModels;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_generate_sample_action_queues_the_job_and_marks_it_pending(): void
    {
        Bus::fake();
        $preset = StylePreset::create([
            'name' => 's', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'x', 'sample_status' => StylePreset::SAMPLE_READY,
        ]);

        Livewire::test(ListStylePresets::class)->callTableAction('sample', $preset);

        Bus::assertDispatched(GenerateStylePresetSampleJob::class, fn ($j): bool => $j->presetId === $preset->id);
        $this->assertSame(StylePreset::SAMPLE_PENDING, $preset->refresh()->sample_status);
    }

    public function test_approve_action_makes_the_preset_live(): void
    {
        $preset = StylePreset::create([
            'name' => 's', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION, 'user_prompt' => 'x',
        ]);

        Livewire::test(ListStylePresets::class)->callTableAction('approve', $preset);

        $this->assertSame(StylePreset::STATUS_APPROVED, $preset->refresh()->status);
    }

    public function test_the_sample_job_fails_soft_when_the_operation_cannot_resolve(): void
    {
        // No AiControlPlaneSeeder here => the operation has no resolvable prompt/model, so the
        // runner path throws. The job must catch it and mark the sample failed (never crash).
        $preset = StylePreset::create([
            'name' => 's', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'A look made of {{materials}}.',
        ]);

        GenerateStylePresetSampleJob::dispatchSync($preset->id);

        $this->assertSame(StylePreset::SAMPLE_FAILED, $preset->refresh()->sample_status);
        $this->assertNull($preset->sample_image_path);
    }

    public function test_style_preset_is_a_registered_global_non_tenant_model(): void
    {
        // It holds no tenant data and only swaps a prompt, so it is deliberately NOT
        // BelongsToAccount — it MUST be on the audited global allow-list.
        $this->assertTrue(GlobalModels::isGlobal(StylePreset::class));
    }
}
