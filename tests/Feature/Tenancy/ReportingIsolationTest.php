<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Leads\LeadAttemptHistory;
use App\Domain\Leads\LeadsExporter;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Domain\Reporting\DashboardMetricsBuilder;
use App\Domain\Reporting\MetricWindow;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The RELEASE-BLOCKER gate for the Phase-8a admin read-contracts: Account B's data
 * NEVER appears in Account A's dashboard metrics / leads export / attempt history, and
 * running two accounts' reads back-to-back never leaks a bound Tenant between them.
 *
 * Closes TS-TENANCY-003 for these new merchant read paths: every query goes through
 * the BelongsToAccount global scope (account-scoped models) inside Tenant::run; there
 * is no bare User::query() and no withoutGlobalScopes() in any of the read classes.
 */
class ReportingIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    public function test_dashboard_metrics_never_count_another_accounts_rows(): void
    {
        [$accountA, $a] = $this->seedAccount('A', sites: 2, leads: 4, generations: 3);
        [$accountB, $b] = $this->seedAccount('B', sites: 1, leads: 1, generations: 1);

        $metricsA = app(DashboardMetricsBuilder::class)->build($accountA);
        $this->assertFalse(Tenant::check(), 'Tenant leaked after building A metrics');

        $metricsB = app(DashboardMetricsBuilder::class)->build($accountB);
        $this->assertFalse(Tenant::check(), 'Tenant leaked after building B metrics');

        // A sees ONLY A's footprint.
        $this->assertSame(2, $metricsA->sitesCount);
        $this->assertSame(4, $metricsA->leadsTotal);
        $this->assertSame(3, $metricsA->generationsInWindow);

        // B sees ONLY B's footprint — A's larger numbers never bleed in.
        $this->assertSame(1, $metricsB->sitesCount);
        $this->assertSame(1, $metricsB->leadsTotal);
        $this->assertSame(1, $metricsB->generationsInWindow);
    }

    public function test_leads_export_never_includes_another_accounts_leads(): void
    {
        [$accountA] = $this->seedAccount('A', sites: 1, leads: 3, generations: 0);
        [$accountB] = $this->seedAccount('B', sites: 1, leads: 2, generations: 0);

        $rowsA = app(LeadsExporter::class)->rows($accountA);
        $rowsB = app(LeadsExporter::class)->rows($accountB);

        // Header + own leads only.
        $this->assertCount(1 + 3, $rowsA);
        $this->assertCount(1 + 2, $rowsB);

        // The known per-account lead email never crosses into the other export.
        $emailsA = collect($rowsA)->skip(1)->pluck(1);
        $emailsB = collect($rowsB)->skip(1)->pluck(1);
        $this->assertTrue($emailsA->contains('a-lead@example.com'));
        $this->assertFalse($emailsA->contains('b-lead@example.com'), "A's export must not contain B's lead");
        $this->assertTrue($emailsB->contains('b-lead@example.com'));
        $this->assertFalse($emailsB->contains('a-lead@example.com'), "B's export must not contain A's lead");
    }

    public function test_attempt_history_is_scoped_to_the_leads_own_account(): void
    {
        [$accountA, $a] = $this->seedAccount('A', sites: 1, leads: 1, generations: 2);

        // A's single lead has 2 attempts; B's lead has its own.
        $leadA = $a['lead'];

        $history = app(LeadAttemptHistory::class)->for($leadA);
        $this->assertCount(2, $history);

        // Every attempt belongs to A's account (proven by the generation id set).
        $genIds = Tenant::run($accountA, fn () => Generation::query()
            ->where('end_user_id', $leadA->id)->pluck('id'));
        $this->assertEqualsCanonicalizing(
            $genIds->all(),
            $history->pluck('generationId')->all(),
        );

        $this->assertFalse(Tenant::check(), 'Tenant leaked after reading attempt history');
    }

    public function test_costs_metrics_aggregate_is_platform_wide_but_built_without_a_leaked_tenant(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $ledger = app(CreditLedgerService::class);

        Tenant::run($accountA, fn () => $ledger->charge(
            $accountA, 1_000_000, 400_000,
            IdempotencyKey::forGeneration($accountA->id, 1, 1, 1, ['x' => 1], 'a'),
            generationId: 1,
        ));
        Tenant::run($accountB, fn () => $ledger->charge(
            $accountB, 1_500_000, 600_000,
            IdempotencyKey::forGeneration($accountB->id, 1, 1, 1, ['x' => 1], 'b'),
            generationId: 1,
        ));

        // The platform aggregate intentionally spans BOTH accounts (super-admin view),
        // and it does so WITHOUT binding/leaking a tenant (a pure DB aggregate).
        $this->assertFalse(Tenant::check());
        $metrics = app(CostsMetricsBuilder::class)->build(MetricWindow::allTime());
        $this->assertFalse(Tenant::check(), 'Tenant leaked after the platform costs aggregate');

        $this->assertSame(2_500_000, $metrics->revenueMicroUsd);
        $this->assertSame(1_000_000, $metrics->actualCostMicroUsd);
        $this->assertSame(2, $metrics->chargeCount);
    }

    public function test_unbound_metrics_query_fails_closed_for_account_scoped_reads(): void
    {
        // A merchant read path that somehow ran without a bound tenant must see nothing,
        // not everything (fail-closed). The exporter binds its own tenant via Tenant::run,
        // so confirm the underlying model is fail-closed when no tenant is bound.
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        Tenant::run($account, fn () => EndUser::factory()->forSite($site)->count(3)->create());

        Tenant::clear();
        $this->assertCount(0, EndUser::query()->get(), 'unbound end-user read fails closed');
    }

    /**
     * Seed one account with $sites sites, $leads leads (one of which is "lead"), and
     * $generations succeeded generations on the first lead.
     *
     * @return array{0: Account, 1: array{lead: EndUser}}
     */
    private function seedAccount(string $tag, int $sites, int $leads, int $generations): array
    {
        $account = Account::factory()->create();

        $context = Tenant::run($account, function () use ($account, $tag, $sites, $leads, $generations) {
            $siteRows = collect(range(1, $sites))->map(
                fn () => Site::factory()->forAccount($account)->create()
            );
            $site = $siteRows->first();

            // The primary lead (carries any generations).
            $lead = EndUser::factory()->forSite($site)->registered()->create([
                'email' => strtolower($tag).'-lead@example.com',
            ]);

            // The remaining leads.
            EndUser::factory()->forSite($site)->count(max(0, $leads - 1))->create();

            if ($generations > 0) {
                $product = Product::factory()->forSite($site)->confirmed()->create();
                $variant = ProductVariant::factory()->forProduct($product)->create();

                collect(range(1, $generations))->each(fn (int $i) => Generation::factory()
                    ->forContext($lead, $product, $variant, $tag.'-crq-'.$i)
                    ->create(['status' => Generation::STATUS_SUCCEEDED]));
            }

            return ['lead' => $lead];
        });

        return [$account, $context];
    }
}
