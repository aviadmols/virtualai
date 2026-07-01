<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\AiModelResource\Pages\CreateAiModel;
use App\Filament\Platform\Resources\AiModelResource\Pages\EditAiModel;
use App\Models\AiModel;
use App\Models\User;
use Database\Seeders\AiControlPlaneSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Models cost-hint field is entered in USD and stored as integer micro-USD. A second
 * conversion on the page classes once nulled the price on every save (the field appeared
 * empty and BytePlus models failed cost_unavailable). These tests pin that the USD input
 * PERSISTS as micro-USD on both create and edit.
 */
class AiModelCostHintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_creating_a_model_persists_the_usd_cost_hint_as_micro_usd(): void
    {
        Livewire::test(CreateAiModel::class)
            ->fillForm([
                'model_id' => 'seedream-5-0-260128',
                'operation_key' => 'try_on_generation',
                'provider' => AiModel::PROVIDER_BYTEPLUS,
                'cost_hint_micro_usd' => '0.035', // entered in USD
                'cost_unit' => AiModel::UNIT_PER_IMAGE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(35_000, (int) AiModel::query()
            ->where('model_id', 'seedream-5-0-260128')
            ->value('cost_hint_micro_usd'));
    }

    public function test_editing_a_model_keeps_the_usd_cost_hint_and_never_nulls_it(): void
    {
        $this->seed(AiControlPlaneSeeder::class);

        $model = AiModel::query()
            ->where('operation_key', 'try_on_generation')
            ->where('model_id', 'seedream-5-0-260128')
            ->firstOrFail();

        Livewire::test(EditAiModel::class, ['record' => $model->getRouteKey()])
            ->fillForm(['cost_hint_micro_usd' => '0.06']) // change the price in USD
            ->call('save')
            ->assertHasNoFormErrors();

        // Persisted as micro-USD — NOT nulled by a second conversion on the page.
        $this->assertSame(60_000, (int) $model->refresh()->cost_hint_micro_usd);
    }

    public function test_edit_form_shows_the_stored_price_in_usd(): void
    {
        $this->seed(AiControlPlaneSeeder::class);

        $model = AiModel::query()
            ->where('operation_key', 'try_on_generation')
            ->where('model_id', 'seedream-5-0-260128')
            ->firstOrFail();
        $model->forceFill(['cost_hint_micro_usd' => 35_000])->save();

        // 35_000 micro-USD renders as "0.035" (no scientific notation, no trailing zeros).
        Livewire::test(EditAiModel::class, ['record' => $model->getRouteKey()])
            ->assertFormSet(['cost_hint_micro_usd' => '0.035']);
    }
}
