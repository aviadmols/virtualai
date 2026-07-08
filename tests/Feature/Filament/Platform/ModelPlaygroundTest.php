<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Pages\ModelPlayground;
use App\Jobs\RunPlaygroundJob;
use App\Models\PlaygroundRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Super-Admin Model Playground page: it renders, and Run creates a (non-tenant, never-charged)
 * PlaygroundRun and dispatches the executor. Video forces the BytePlus provider regardless of a
 * stale selection.
 */
class ModelPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
        config()->set('trayon.media.disk', 's3');
    }

    public function test_the_page_renders(): void
    {
        Livewire::test(ModelPlayground::class)->assertOk();
    }

    public function test_run_creates_a_run_and_dispatches_the_job(): void
    {
        Bus::fake([RunPlaygroundJob::class]);

        Livewire::test(ModelPlayground::class)
            ->fillForm([
                'kind' => PlaygroundRun::KIND_IMAGE,
                'provider' => PlaygroundRun::PROVIDER_BYTEPLUS,
                'model_id' => 'seedream-5-0-260128',
                'prompt' => 'a red dress on a studio model',
                'price' => '0.035',
            ])
            ->call('run')
            ->assertHasNoFormErrors();

        $run = PlaygroundRun::query()->firstOrFail();
        $this->assertSame(PlaygroundRun::KIND_IMAGE, $run->kind);
        $this->assertSame('seedream-5-0-260128', $run->model_id);
        $this->assertSame(PlaygroundRun::STATUS_QUEUED, $run->status);
        $this->assertSame(35_000, $run->price_hint_micro_usd);

        Bus::assertDispatched(RunPlaygroundJob::class);
    }

    public function test_a_video_run_is_forced_onto_byteplus(): void
    {
        Bus::fake([RunPlaygroundJob::class]);

        Livewire::test(ModelPlayground::class)
            ->fillForm([
                'kind' => PlaygroundRun::KIND_VIDEO,
                'model_id' => 'dreamina-seedance-2-0-260128',
                'prompt' => 'the model walks forward',
                'resolution' => '720p',
                'duration' => 5,
            ])
            ->call('run')
            ->assertHasNoFormErrors();

        $run = PlaygroundRun::query()->firstOrFail();
        $this->assertSame(PlaygroundRun::KIND_VIDEO, $run->kind);
        $this->assertSame(PlaygroundRun::PROVIDER_BYTEPLUS, $run->provider);
        $this->assertSame('720p', $run->meta[PlaygroundRun::META_RESOLUTION]);
    }
}
