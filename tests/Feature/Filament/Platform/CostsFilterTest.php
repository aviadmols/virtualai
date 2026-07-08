<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Reporting\MetricWindow;
use App\Filament\Platform\Pages\Dashboard;
use App\Filament\Platform\Widgets\AccountCostsWidget;
use App\Filament\Platform\Widgets\CostsVsRevenueWidget;
use App\Filament\Platform\Widgets\ProviderCostsWidget;
use App\Models\Account;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Super-Admin costs view's date filter: MetricWindow::between covers whole days; the page
 * renders with the filter form; and the costs widget re-queries for the selected period (a rolling
 * days window or a custom range) — charges outside the window drop out of revenue.
 */
class CostsFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    private function charge(Account $account, int $selling, int $cost, int $gen): void
    {
        Tenant::run($account, fn () => app(CreditLedgerService::class)->charge(
            account: $account,
            chargeMicroUsd: $selling,
            actualCostMicroUsd: $cost,
            idempotencyKey: IdempotencyKey::forGeneration($account->id, 1, 1, 1, ['g' => $gen], (string) $gen),
            generationId: $gen,
        ));
    }

    private function backdate(int $costMicro, int $days): void
    {
        DB::table('credit_ledger')->where('type', 'charge')
            ->where('actual_cost_micro_usd', $costMicro)
            ->update(['created_at' => CarbonImmutable::now()->subDays($days)]);
    }

    public function test_between_covers_whole_days(): void
    {
        $w = MetricWindow::between(
            CarbonImmutable::parse('2026-03-01 15:00'),
            CarbonImmutable::parse('2026-03-05 09:00'),
        );

        $this->assertStringStartsWith('2026-03-01T00:00:00', (string) $w->fromIso());
        $this->assertStringStartsWith('2026-03-05T23:59:59', (string) $w->untilIso());
    }

    public function test_the_platform_dashboard_renders_with_the_filter(): void
    {
        Livewire::test(Dashboard::class)->assertOk();
    }

    public function test_the_costs_widget_respects_the_rolling_period_filter(): void
    {
        $account = Account::factory()->create();
        $this->charge($account, 1_000_000, 400_000, 1);   // now
        $this->charge($account, 9_000_000, 3_600_000, 2); // to be backdated 60 days
        $this->backdate(3_600_000, 60);

        $last7 = Livewire::test(CostsVsRevenueWidget::class, ['filters' => ['period' => '7']])
            ->instance()->getSummary();
        $this->assertSame('$1.00', $last7['revenue'], 'the 60-day-old charge is outside a 7-day window');

        $last90 = Livewire::test(CostsVsRevenueWidget::class, ['filters' => ['period' => '90']])
            ->instance()->getSummary();
        $this->assertSame('$10.00', $last90['revenue'], 'a 90-day window includes both charges');
    }

    public function test_the_account_breakdown_widget_formats_per_account_rows(): void
    {
        $account = Account::factory()->create(['name' => 'Kollector']);
        $this->charge($account, 1_000_000, 400_000, 1);

        $accounts = Livewire::test(AccountCostsWidget::class, ['filters' => ['period' => '30']])
            ->instance()->getAccounts();

        $this->assertTrue($accounts['hasData']);
        $this->assertSame('Kollector', $accounts['rows'][0]['name']);
        $this->assertSame('$0.40', $accounts['rows'][0]['cost']);
        $this->assertSame('$1.00', $accounts['rows'][0]['revenue']);
        $this->assertSame('$0.60', $accounts['rows'][0]['margin']);
    }

    public function test_the_provider_widget_always_shows_both_providers(): void
    {
        // With no generations yet, spend is zero — but the comparison still lists both providers.
        $providers = Livewire::test(ProviderCostsWidget::class, ['filters' => ['period' => '30']])
            ->instance()->getProviders();

        $this->assertFalse($providers['hasData']);
        $this->assertCount(2, $providers['cards']);
        $this->assertSame('$0.00', $providers['cards'][0]['value']);
    }

    public function test_a_custom_range_narrows_to_that_range(): void
    {
        $account = Account::factory()->create();
        $this->charge($account, 5_000_000, 2_000_000, 1); // now
        $this->charge($account, 3_000_000, 1_200_000, 2); // to be backdated 10 days
        $this->backdate(1_200_000, 10);

        $filters = [
            'period' => 'custom',
            'from' => CarbonImmutable::now()->subDays(3)->toDateString(),
            'until' => CarbonImmutable::now()->toDateString(),
        ];

        $summary = Livewire::test(CostsVsRevenueWidget::class, ['filters' => $filters])->instance()->getSummary();
        $this->assertSame('$5.00', $summary['revenue'], 'a 3-day custom range excludes the 10-day-old charge');
    }
}
