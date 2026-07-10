<?php

namespace Tests\Feature\Storyboard;

use App\Filament\Platform\Pages\StoryboardPipelineSettings;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\User;
use Database\Seeders\StoryboardPipelineSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
        // The fal Model picker merges the live public catalog — keep tests off the network.
        Http::fake(['https://fal.ai/api/models*' => Http::response(['items' => []], 200)]);
    }

    public function test_the_page_renders_with_the_seeded_steps(): void
    {
        Livewire::test(StoryboardPipelineSettings::class)
            ->assertOk()
            ->assertFormSet(fn (array $state): bool => ($state['storyboard_read_idea']['model'] ?? null) === 'google/gemini-3.1-pro-preview');
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
        // The string param is coerced to a number on save (a string temperature is a 400).
        $this->assertSame(0.9, $op->params['temperature']);

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

    public function test_the_director_step_renders_and_its_test_runs_the_text_caller(): void
    {
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        Sleep::fake();

        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"video_prompt":"[00:00-00:03] wide shot"}']]],
            'model' => 'google/gemini-3.1-pro-preview',
            'usage' => ['cost' => 0.001],
        ], 200)]);

        // The director is an ON-DEMAND text op (not a pipeline step) — the Test button must still
        // run the real text caller instead of pointing to the Playground.
        Livewire::test(StoryboardPipelineSettings::class)
            ->assertFormSet(fn (array $state): bool => ($state['storyboard_video_director']['model'] ?? null) === 'google/gemini-3.1-pro-preview')
            ->call('testStep', AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR)
            ->assertNotified();
    }

    public function test_testing_a_step_reports_success_when_it_returns_json(): void
    {
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        Sleep::fake();

        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"genre":"comedy","emotional_tone":"fun"}']]],
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.001],
        ], 200)]);

        Livewire::test(StoryboardPipelineSettings::class)
            ->call('testStep', AiOperation::KEY_STORYBOARD_GENRE)
            ->assertNotified();
    }
}
