<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\AiModelResource;
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
 * The Models "Test" row action — a no-spend probe of a single model against its provider AT ITS
 * REGION HOST, opening a modal that shows the EXACT provider response. Answers "does this model
 * work?" (and "why not?") without running a real try-on.
 */
class AiModelTestActionTest extends TestCase
{
    use RefreshDatabase;

    private const TRYON = 'try_on_generation';
    private const SEEDREAM = 'seedream-5-0-260128';
    private const AP = 'https://ark.ap-southeast.bytepluses.com/api/v3';
    private const EU = 'https://ark.eu-west.bytepluses.com/api/v3';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->seed(AiControlPlaneSeeder::class);
        Sleep::fake();
    }

    private function seedream(): AiModel
    {
        return AiModel::query()->where('operation_key', self::TRYON)->where('model_id', self::SEEDREAM)->firstOrFail();
    }

    public function test_probe_reports_byteplus_no_access_with_the_raw_response(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::EU);
        Http::fake(['*/images/generations' => Http::response(['error' => ['message' => 'does not exist or you do not have access']], 404)]);

        $result = AiModelResource::probeModel($this->seedream());

        $this->assertFalse($result['ok']);
        // The exact provider response (host + HTTP status + body) is surfaced for "more detail".
        $this->assertStringContainsString('404', $result['raw']);
        $this->assertStringContainsString('does not exist', $result['raw']);
    }

    public function test_probe_uses_the_per_model_region_host(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::EU); // default region differs
        Http::fake([
            'ark.ap-southeast.bytepluses.com/*' => Http::response(['data' => [['b64_json' => base64_encode('x')]]], 200),
            '*' => Http::response(['error' => ['message' => 'wrong region']], 404),
        ]);

        $model = $this->seedream();
        $model->forceFill(['base_url' => self::AP])->save();

        $result = AiModelResource::probeModel($model);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'ap-southeast'));
    }

    public function test_probe_ignores_a_non_byteplus_region_host(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::EU);
        Http::fake([
            'ark.eu-west.bytepluses.com/*' => Http::response(['data' => [['b64_json' => base64_encode('x')]]], 200),
            '*' => Http::response([], 404),
        ]);

        $model = $this->seedream();
        $model->forceFill(['base_url' => 'https://evil.example.com/api/v3'])->save();

        AiModelResource::probeModel($model);

        // Key-safety: the platform BytePlus key is only ever sent to a bytepluses.com host —
        // the bad override is ignored and the configured default host is used instead.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'eu-west'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.example.com'));
    }

    public function test_probe_reports_openrouter_catalog_hit(): void
    {
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'google/gemini-3.1-flash-image']]], 200)]);

        $model = AiModel::query()->where('operation_key', self::TRYON)->where('model_id', 'google/gemini-3.1-flash-image')->firstOrFail();

        $this->assertTrue(AiModelResource::probeModel($model)['ok']);
    }

    public function test_test_action_modal_renders_the_provider_response(): void
    {
        config()->set('services.byteplus.api_key', 'bp-real-key');
        config()->set('services.byteplus.base_url', self::EU);
        Http::fake(['*/images/generations' => Http::response(['error' => ['message' => 'no access to it here']], 404)]);

        Livewire::test(ListAiModels::class)
            ->mountTableAction('test', $this->seedream())
            ->assertHasNoTableActionErrors()
            ->assertSee('no access to it here'); // the raw provider response is visible in the modal

        Http::assertSent(fn ($req) => str_contains($req->url(), '/images/generations'));
    }
}
