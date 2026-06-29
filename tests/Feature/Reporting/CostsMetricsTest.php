<?php

namespace Tests\Feature\Reporting;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Reporting\CostsMetrics;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Models\Account;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CostsMetrics correctness: the PLATFORM-WIDE costs view sums revenue vs real cost
 * across ALL accounts from the `charge` ledger rows and derives the realized markup.
 */
class CostsMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): CostsMetricsBuilder
    {
        return app(CostsMetricsBuilder::class);
    }

    private function ledger(): CreditLedgerService
    {
        return app(CreditLedgerService::class);
    }

    /** Write a charge row for $account at the given selling value + real cost. */
    private function charge(Account $account, int $sellingMicro, int $costMicro, int $generationId): void
    {
        Tenant::run($account, fn () => $this->ledger()->charge(
            account: $account,
            chargeMicroUsd: $sellingMicro,
            actualCostMicroUsd: $costMicro,
            idempotencyKey: IdempotencyKey::forGeneration($account->id, 1, 1, 1, ['g' => $generationId], (string) $generationId),
            generationId: $generationId,
        ));
    }

    public function test_it_sums_revenue_cost_and_realized_markup_across_all_accounts(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        // A: $1.00 selling on $0.40 cost. B: $1.50 selling on $0.60 cost.
        $this->charge($accountA, 1_000_000, 400_000, 1);
        $this->charge($accountB, 1_500_000, 600_000, 2);

        $metrics = $this->builder()->build(MetricWindow::allTime());

        $this->assertInstanceOf(CostsMetrics::class, $metrics);

        // Revenue = 1.00 + 1.50 = 2.50; cost = 0.40 + 0.60 = 1.00; margin = 1.50.
        $this->assertSame(2_500_000, $metrics->revenueMicroUsd);
        $this->assertSame(1_000_000, $metrics->actualCostMicroUsd);
        $this->assertSame(1_500_000, $metrics->grossMarginMicroUsd);
        $this->assertSame(2, $metrics->chargeCount);

        // Realized markup = revenue / cost = 2.5 (matches the configured target here).
        $this->assertSame(2.5, $metrics->markupRealized);
        $this->assertSame(2.5, $metrics->markupTarget);
        $this->assertTrue($metrics->hasCostData());

        // Margin ratio = 1.5 / 2.5 = 0.6.
        $this->assertSame(0.6, $metrics->marginRatio());
    }

    public function test_no_charges_yields_zero_metrics_and_no_divide_by_zero(): void
    {
        Account::factory()->create(); // only an opening grant row, no charges

        $metrics = $this->builder()->build(MetricWindow::allTime());

        $this->assertSame(0, $metrics->revenueMicroUsd);
        $this->assertSame(0, $metrics->actualCostMicroUsd);
        $this->assertSame(0, $metrics->grossMarginMicroUsd);
        $this->assertSame(0, $metrics->chargeCount);
        $this->assertSame(0.0, $metrics->markupRealized);
        $this->assertSame(0.0, $metrics->marginRatio());
        $this->assertFalse($metrics->hasCostData());
    }

    public function test_only_charge_rows_count_not_grants_or_purchases(): void
    {
        $account = Account::factory()->create(); // opening GRANT (positive, type=grant)

        // A purchase top-up (positive, type=purchase) — must NOT count as revenue.
        Tenant::run($account, fn () => $this->ledger()->purchase(
            $account, 10_000_000, IdempotencyKey::forPurchase($account->id, 'payplus', 'ref-1'),
        ));

        // One real charge.
        $this->charge($account, 1_000_000, 400_000, 1);

        $metrics = $this->builder()->build(MetricWindow::allTime());

        // Only the single charge row is counted.
        $this->assertSame(1, $metrics->chargeCount);
        $this->assertSame(1_000_000, $metrics->revenueMicroUsd);
        $this->assertSame(400_000, $metrics->actualCostMicroUsd);
    }

    public function test_window_excludes_charges_outside_the_period(): void
    {
        $account = Account::factory()->create();

        $this->charge($account, 1_000_000, 400_000, 1); // now
        $this->charge($account, 9_000_000, 1_000_000, 2); // about to be backdated

        // Backdate the second charge 60 days so a 30-day window excludes it.
        DB::table('credit_ledger')
            ->where('type', 'charge')
            ->where('actual_cost_micro_usd', 1_000_000)
            ->update(['created_at' => CarbonImmutable::now()->subDays(60)]);

        $last30 = $this->builder()->build(MetricWindow::lastDays(30));
        $this->assertSame(1, $last30->chargeCount);
        $this->assertSame(1_000_000, $last30->revenueMicroUsd);

        $allTime = $this->builder()->build(MetricWindow::allTime());
        $this->assertSame(2, $allTime->chargeCount);
    }
}
