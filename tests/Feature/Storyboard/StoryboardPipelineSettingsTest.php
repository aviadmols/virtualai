<?php

namespace Tests\Feature\Storyboard;

use App\Filament\Platform\Pages\StoryboardPipelineSettings;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\User;
use Database\Seeders\StoryboardPipelineSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Storyboard Pipeline Settings page: control every step's engine (provider/model) + prompts +
 * params in one place, writing through to ai_operations / prompts / ai_models so the pipeline picks
 * the changes up on the next run.
 */
class StoryboardPipelineSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->seed(StoryboardPipelineSeeder::class);
    }

    public function test_the_page_renders_with_the_seeded_steps(): void
    {
        Livewire::test(StoryboardPipelineSettings::class)
            ->assertOk()
            ->assertFormSet(fn (array $state): bool => ($state['storyboard_read_idea']['model'] ?? null) === 'google/gemini-2.5-flash');
    }

    public function test_saving_updates_the_operation_prompt_and_model(): void
    {
        Livewire::test(StoryboardPipelineSettings::class)
            ->fillForm([
                'storyboard_read_idea' => [
                    'provider' => 'openrouter',
                    'model' => 'google/gemini-custom',
                    'system_prompt' => 'NEW SYSTEM',
                    'user_prompt' => 'NEW USER {{story_idea}}',
                    'params' => ['temperature' => '0.9'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $op = AiOperation::query()->where('operation_key', AiOperation::KEY_STORYBOARD_READ_IDEA)->first();
        $this->assertSame('google/gemini-custom', $op->default_model);
        $this->assertSame('0.9', $op->params['temperature']);

        $this->assertDatabaseHas('ai_models', [
            'operation_key' => AiOperation::KEY_STORYBOARD_READ_IDEA,
            'model_id' => 'google/gemini-custom',
            'is_default' => true,
        ]);

        $prompt = Prompt::query()
            ->where('scope', Prompt::SCOPE_GLOBAL)
            ->where('operation_key', AiOperation::KEY_STORYBOARD_READ_IDEA)
            ->first();
        $this->assertSame('NEW SYSTEM', $prompt->system_prompt);
        $this->assertStringContainsString('{{story_idea}}', $prompt->user_prompt);
    }
}
