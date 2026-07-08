<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Reporting\GenerationLogBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Filament\Platform\Pages\GenerationLog;
use App\Models\Account;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Super-Admin generation log: a cross-account list of each try-on with its provider, status,
 * measured render time + cost. Proves the builder attributes the provider + duration, spans every
 * account, and the page renders.
 */
class GenerationLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    private function succeededTryOn(Account $account, string $modelUsed, int $durationMs, int $costMicro): void
    {
        Tenant::run($account, function () use ($account, $modelUsed, $durationMs, $costMicro): void {
            $site = Site::factory()->forAccount($account)->create();
            $endUser = EndUser::factory()->forSite($site)->create();
            $product = Product::factory()->forSite($site)->confirmed()->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();

            Generation::factory()->forContext($endUser, $product, $variant, uniqid('crq_'))->create([
                'status' => Generation::STATUS_SUCCEEDED,
                'model_used' => $modelUsed,
                'duration_ms' => $durationMs,
                'actual_cost_micro_usd' => $costMicro,
            ]);
        });
    }

    public function test_recent_returns_each_try_on_with_provider_and_render_time(): void
    {
        AiModel::factory()->create(['operation_key' => AiOperation::KEY_TRY_ON_GENERATION, 'model_id' => 'or/model', 'provider' => 'openrouter']);
        $account = Account::factory()->create(['name' => 'Kollector']);

        $this->succeededTryOn($account, 'or/model', 1234, 400_000);

        $rows = app(GenerationLogBuilder::class)->recent(MetricWindow::allTime());

        $this->assertCount(1, $rows);
        $this->assertSame('Kollector', $rows[0]['accountName']);
        $this->assertSame('or/model', $rows[0]['modelUsed']);
        $this->assertSame('openrouter', $rows[0]['provider']);
        $this->assertSame('succeeded', $rows[0]['status']);
        $this->assertSame(1234, $rows[0]['durationMs']);
        $this->assertSame(400_000, $rows[0]['costMicroUsd']);
    }

    public function test_the_log_spans_every_account(): void
    {
        $this->succeededTryOn(Account::factory()->create(['name' => 'Alpha']), 'x', 100, 1);
        $this->succeededTryOn(Account::factory()->create(['name' => 'Beta']), 'x', 200, 1);

        $names = array_column(app(GenerationLogBuilder::class)->recent(MetricWindow::allTime()), 'accountName');

        $this->assertContains('Alpha', $names);
        $this->assertContains('Beta', $names);
    }

    public function test_the_log_page_renders_and_formats_rows(): void
    {
        $this->succeededTryOn(Account::factory()->create(['name' => 'Kollector']), 'or/model', 1500, 500_000);

        Livewire::test(GenerationLog::class)
            ->assertOk()
            ->assertSee('Kollector')
            ->assertSee('1,500 ms');
    }
}
