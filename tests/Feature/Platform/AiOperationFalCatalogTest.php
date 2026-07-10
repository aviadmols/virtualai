<?php

namespace Tests\Feature\Platform;

use App\Filament\Platform\Resources\AiOperationResource;
use App\Filament\Platform\Resources\AiOperationResource\Pages\EditAiOperation;
use App\Models\AiOperation;
use App\Models\User;
use Database\Seeders\AiControlPlaneSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI-Operations screen offers the FULL fal.ai image catalog for the image operations
 * (try-on, banner, storyboard frame), and a picked fal model is AUTO-CATALOGUED with
 * provider=fal (+ fal's advisory price as the cost hint) — else the resolver would default
 * its provider to OpenRouter and route the generation to the wrong upstream.
 */
class AiOperationFalCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const FAL_MODEL = 'fal-ai/flux/dev';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->seed(AiControlPlaneSeeder::class);
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);

        Http::fake(['https://fal.ai/api/models*' => Http::response(['items' => [[
            'id' => self::FAL_MODEL,
            'title' => 'FLUX.1 [dev]',
            'category' => 'text-to-image',
            'pricingInfoOverride' => 'Your request will cost **$0.025** per image.',
        ]]], 200)]);
    }

    public function test_the_image_operations_offer_the_fal_catalog(): void
    {
        $options = AiOperationResource::modelOptions(AiOperation::KEY_TRY_ON_GENERATION);
        $this->assertArrayHasKey(self::FAL_MODEL, $options);

        $options = AiOperationResource::modelOptions(AiOperation::KEY_BANNER_GENERATION);
        $this->assertArrayHasKey(self::FAL_MODEL, $options);

        // A non-image operation does NOT browse the fal catalog.
        $options = AiOperationResource::modelOptions(AiOperation::KEY_PRODUCT_SCAN);
        $this->assertArrayNotHasKey(self::FAL_MODEL, $options);
    }

    public function test_saving_a_fal_model_auto_catalogues_it_with_its_provider_and_price(): void
    {
        $operation = AiOperation::query()->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)->firstOrFail();

        Livewire::test(EditAiOperation::class, ['record' => $operation->getRouteKey()])
            ->set('data.default_model', self::FAL_MODEL)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::FAL_MODEL, $operation->refresh()->default_model);
        $this->assertDatabaseHas('ai_models', [
            'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'model_id' => self::FAL_MODEL,
            'provider' => 'fal',
            'cost_hint_micro_usd' => 25_000, // parsed from fal's advisory pricing text
            'is_active' => true,
        ]);
    }
}
