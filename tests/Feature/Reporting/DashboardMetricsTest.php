<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DashboardMetricsBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardMetrics correctness: the merchant-home snapshot computes the right
 * account-scoped counts/sums over the window, with the success rate and low-balance
 * flag derived correctly.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): DashboardMetricsBuilder
    {
        return app(DashboardMetricsBuilder::class);
    }

    public function test_it_computes_catalog_credit_lead_and_generation_metrics(): void
    {
        $account = Account::factory()->create(); // $5 opening grant -> 5_000_000

        [$siteA, $siteB] = [
            Site::factory()->forAccount($account)->create(),
            Site::factory()->forAccount($account)->create(),
        ];

        Tenant::run($account, function () use ($siteA, $siteB): void {
            // Products: 3 total, 2 confirmed.
            Product::factory()->forSite($siteA)->confirmed()->create();
            Product::factory()->forSite($siteA)->confirmed()->create();
            Product::factory()->forSite($siteB)->create(); // draft

            // Leads: 4 total, 2 registered.
            EndUser::factory()->forSite($siteA)->count(2)->create();
            EndUser::factory()->forSite($siteB)->registered()->count(2)->create();

            $endUser = EndUser::factory()->forSite($siteA)->create();
            $product = Product::factory()->forSite($siteA)->confirmed()->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();

            // Generations in-window: 3 succeeded, 1 failed, 1 pending.
            $this->makeGenerations($endUser, $product, $variant, Generation::STATUS_SUCCEEDED, 3);
            $this->makeGenerations($endUser, $product, $variant, Generation::STATUS_FAILED, 1);
            $this->makeGenerations($endUser, $product, $variant, Generation::STATUS_PENDING, 1);
        });

        $metrics = $this->builder()->build($account, MetricWindow::lastDays());

        $this->assertInstanceOf(DashboardMetrics::class, $metrics);

        // Catalog: 2 sites; products 4 total (3 confirmed + 1 draft), 3 confirmed.
        $this->assertSame(2, $metrics->sitesCount);
        $this->assertSame(4, $metrics->productsTotal);
        $this->assertSame(3, $metrics->productsConfirmed);

        // Generations: 5 in window, 3 succeeded, 1 failed; success rate = 3/4 = 0.75.
        $this->assertSame(5, $metrics->generationsInWindow);
        $this->assertSame(3, $metrics->generationsSucceededInWindow);
        $this->assertSame(1, $metrics->generationsFailedInWindow);
        $this->assertSame(0.75, $metrics->successRate);

        // Leads: 5 total (2 + 2 + 1), 2 registered.
        $this->assertSame(5, $metrics->leadsTotal);
        $this->assertSame(2, $metrics->leadsRegistered);

        // Credits: fresh opening grant, nothing reserved/spent.
        $this->assertSame(5_000_000, $metrics->balanceMicroUsd);
        $this->assertSame(0, $metrics->reservedMicroUsd);
        $this->assertSame(5_000_000, $metrics->spendableMicroUsd);
        $this->assertFalse($metrics->isLowBalance);
        $this->assertFalse($metrics->isOutOfCredits());
    }

    public function test_success_rate_is_zero_with_no_terminal_attempts(): void
    {
        $account = Account::factory()->create();
        $metrics = $this->builder()->build($account);

        $this->assertSame(0.0, $metrics->successRate);
        $this->assertFalse($metrics->hasGenerationData());
    }

    public function test_low_balance_flag_uses_per_site_override_then_account_default(): void
    {
        // The documented threshold: MAX per-site override across the account's sites,
        // falling back to the config default. config default is $1 (1_000_000).
        $account = Account::factory()->create(); // 5_000_000 balance

        // One site warns at $6 (6_000_000) — above balance -> low.
        Site::factory()->forAccount($account)->create([
            'usage_limits' => ['low_balance_micro_usd' => 6_000_000],
        ]);
        // Another site warns at $2 (less cautious); the most cautious site wins.
        Site::factory()->forAccount($account)->create([
            'usage_limits' => ['low_balance_micro_usd' => 2_000_000],
        ]);

        $metrics = $this->builder()->build($account);
        $this->assertTrue($metrics->isLowBalance, 'spendable 5_000_000 <= max override 6_000_000');
    }

    public function test_low_balance_flag_falls_back_to_config_default_when_no_override(): void
    {
        $account = Account::factory()->create(); // 5_000_000 balance
        Site::factory()->forAccount($account)->create(); // no override

        // Default threshold ($1) is far below the $5 balance -> not low.
        $metrics = $this->builder()->build($account);
        $this->assertFalse($metrics->isLowBalance);
    }

    public function test_window_excludes_rows_outside_the_period(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($site): void {
            $endUser = EndUser::factory()->forSite($site)->create();
            $product = Product::factory()->forSite($site)->confirmed()->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();

            // One generation inside the window (now).
            $this->makeGenerations($endUser, $product, $variant, Generation::STATUS_SUCCEEDED, 1);

            // One generation 60 days ago — outside a 30-day window.
            $old = $this->makeGenerations($endUser, $product, $variant, Generation::STATUS_SUCCEEDED, 1)->first();
            $old->forceFill(['created_at' => CarbonImmutable::now()->subDays(60)])->save();
        });

        $last30 = $this->builder()->build($account, MetricWindow::lastDays(30));
        $this->assertSame(1, $last30->generationsInWindow, 'the 60-day-old row is excluded');

        $allTime = $this->builder()->build($account, MetricWindow::allTime());
        $this->assertSame(2, $allTime->generationsInWindow, 'all-time counts both');
    }

    /**
     * Create $count generations in a given status for the context. Returns the
     * created collection. Each carries a unique idempotency key (status in the suffix).
     */
    private function makeGenerations(EndUser $endUser, Product $product, ProductVariant $variant, string $status, int $count)
    {
        return collect(range(1, $count))->map(function (int $i) use ($endUser, $product, $variant, $status) {
            $crq = $status.'-'.$i.'-'.uniqid();

            return Generation::factory()
                ->forContext($endUser, $product, $variant, $crq)
                ->create(['status' => $status]);
        });
    }
}
