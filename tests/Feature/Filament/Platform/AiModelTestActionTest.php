<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\AiModelResource\Pages\ListAiModels;
use App\Models\AiModel;
use App\Models\User;
use Database\Seeders\AiControlPlaneSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Models "Test" row action — a no-spend probe of a single model against its provider, so
 * the admin can answer "does this model work?" without running a real try-on. Proves the
 * action routes to the right provider endpoint (BytePlus /images/generations, OpenRouter
 * /models) and never errors the page.
 */
class AiModelTestActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->seed(AiControlPlaneSeeder::class);
        Sleep::fake();
    }

    public function test_action_probes_byteplus_model_and_reports_no_access(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', 'https://ark.eu-west.bytepluses.com/api/v3');
        Http::fake(['*/images/generations' => Http::response(['error' => ['message' => 'does not exist or you do not have access']], 404)]);

        $model = AiModel::query()->where('operation_key', 'try_on_generation')
            ->where('model_id', 'seedream-5-0-260128')->firstOrFail();

        Livewire::test(ListAiModels::class)
            ->callTableAction('test', $model)
            ->assertHasNoTableActionErrors();

        // The probe hit BytePlus with THIS model id (a real, cheap "ping").
        Http::assertSent(fn ($req) => str_contains($req->url(), '/images/generations') && $req['model'] === 'seedream-5-0-260128');
    }

    public function test_action_probes_openrouter_model_catalog(): void
    {
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'google/gemini-3.1-flash-image']]], 200)]);

        $model = AiModel::query()->where('operation_key', 'try_on_generation')
            ->where('model_id', 'google/gemini-3.1-flash-image')->firstOrFail();

        Livewire::test(ListAiModels::class)
            ->callTableAction('test', $model)
            ->assertHasNoTableActionErrors();

        // The OpenRouter probe reads the catalog (no paid completion).
        Http::assertSent(fn ($req) => str_contains($req->url(), '/models'));
    }
}
