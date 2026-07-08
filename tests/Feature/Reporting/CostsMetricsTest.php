<?php

namespace Tests\Feature\Reporting;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Reporting\CostsMetrics;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Models\Account;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
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

    /** A succeeded try-on on a given model with a given real cost (+ optional render duration). */
    private function succeededGeneration(Account $account, string $modelUsed, int $costMicro, ?int $durationMs = null): void
    {
        Tenant::run($account, function () use ($account, $modelUsed, $costMicro, $durationMs): void {
            $site = Site::factory()->forAccount($account)->create();
            $endUser = EndUser::factory()->forSite($site)->create();
            $product = Product::factory()->forSite($site)->confirmed()->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();

            Generation::factory()->forContext($endUser, $product, $variant, uniqid('crq_'))->create([
                'status' => Generation::STATUS_SUCCEEDED,
                'model_used' => $modelUsed,
                'actual_cost_micro_usd' => $costMicro,
                'duration_ms' => $durationMs,
            ]);
        });
    }

    public function test_generation_timings_average_render_time_per_day(): void
    {
        $account = Account::factory()->create();

        // Today: two try-ons at 1000ms + 2000ms (avg 1500). "Yesterday": one at 500ms.
        $this->succeededGeneration($account, 'or/model', 100_000, 1000);
        $this->succeededGeneration($account, 'or/model', 100_000, 2000);
        $this->succeededGeneration($account, 'or/model', 100_000, 500);
        DB::table('generations')->where('duration_ms', 500)->update(['created_at' => CarbonImmutable::now()->subDay()]);

        $rows = $this->builder()->generationTimings(MetricWindow::lastDays(7));

        // Oldest first: yesterday (avg 500, n=1) then today (avg 1500, n=2).
        $this->assertCount(2, $rows);
        $this->assertSame(500, $rows[0]['avgMs']);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame(1500, $rows[1]['avgMs']);
        $this->assertSame(2, $rows[1]['count']);
    }

    public function test_by_provider_splits_cost_between_openrouter_and_byteplus(): void
    {
        AiModel::factory()->create(['operation_key' => AiOperation::KEY_TRY_ON_GENERATION, 'model_id' => 'or/model', 'provider' => 'openrouter']);
        AiModel::factory()->create(['operation_key' => AiOperation::KEY_TRY_ON_GENERATION, 'model_id' => 'bp/model', 'provider' => 'byteplus']);

        $account = Account::factory()->create();
        $this->succeededGeneration($account, 'or/model', 400_000);
        $this->succeededGeneration($account, 'or/model', 400_000);
        $this->succeededGeneration($account, 'bp/model', 300_000);

        $by = (new Collection($this->builder()->byProvider(MetricWindow::allTime())))->keyBy('provider');

        $this->assertSame(800_000, $by['openrouter']['costMicroUsd']);
        $this->assertSame(2, $by['openrouter']['count']);
        $this->assertSame(300_000, $by['byteplus']['costMicroUsd']);
        $this->assertSame(1, $by['byteplus']['count']);
    }

    public function test_by_account_splits_cost_and_revenue_per_account(): void
    {
        $alpha = Account::factory()->create(['name' => 'Alpha']);
        $beta = Account::factory()->create(['name' => 'Beta']);

        $this->charge($alpha, 1_000_000, 400_000, 1);
        $this->charge($alpha, 1_000_000, 400_000, 2);
        $this->charge($beta, 1_500_000, 600_000, 3);

        $rows = $this->builder()->byAccount(MetricWindow::allTime());
        $by = (new Collection($rows))->keyBy('accountName');

        // Alpha: revenue 2.0, cost 0.8, margin 1.2, 2 charges — never mixed with Beta's.
        $this->assertSame(2_000_000, $by['Alpha']['revenueMicroUsd']);
        $this->assertSame(800_000, $by['Alpha']['costMicroUsd']);
        $this->assertSame(1_200_000, $by['Alpha']['marginMicroUsd']);
        $this->assertSame(2, $by['Alpha']['charges']);
        $this->assertSame(1_500_000, $by['Beta']['revenueMicroUsd']);
        // Ordered by revenue desc → Alpha ($2.00) before Beta ($1.50).
        $this->assertSame('Alpha', $rows[0]['accountName']);
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
