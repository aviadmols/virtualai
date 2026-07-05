<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Activity\ActivityRecorder;
use App\Filament\Merchant\Resources\SiteResource\Pages\ViewSite;
use App\Models\ActivityEvent;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WS1 — the per-shop OVERVIEW hub (ViewSite). Ties the shop's tools together: a KPI
 * band (confirmed products, try-ons, leads, spendable credit), quick-link cards to
 * the shop's management surfaces, and a recent-activity strip.
 *
 * These prove the hub renders for the owner (KPI band + quick-links + activity), the
 * KPI figures are the shop's own (account+site-scoped — a foreign shop's data never
 * leaks), and a hand-crafted URL for another account's shop 404s through the
 * account-scoped record binding.
 */
class ShopHubOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Account $accountA;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountA = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->accountA)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->accountA)->create());
        // Shop-centric panel: bind the active shop so the per-tenant URLs resolve.
        Filament::setTenant($this->site);
    }

    public function test_hub_renders_the_kpi_band_and_quick_links_for_the_owner(): void
    {
        Tenant::run($this->accountA->id, function (): void {
            Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])
                ->assertOk()
                // KPI band labels.
                ->assertSee(__('sites.hub.kpi.products'))
                ->assertSee(__('sites.hub.kpi.generations'))
                ->assertSee(__('sites.hub.kpi.leads'))
                ->assertSee(__('sites.hub.kpi.balance'))
                // Quick-link cards.
                ->assertSee(__('sites.hub.tools.placement.title'))
                ->assertSee(__('sites.hub.tools.history.title'))
                ->assertSee(__('sites.hub.tools.users.title'))
                ->assertSee(__('sites.hub.tools.gallery.title'))
                ->assertSee(__('sites.hub.tools.privacy.title'))
                // Recent-activity strip header.
                ->assertSee(__('sites.hub.activity.title'));
        });
    }

    public function test_hub_kpi_band_counts_only_this_shops_data(): void
    {
        Tenant::run($this->accountA->id, function (): void {
            // One confirmed + one draft product on this shop; only the confirmed counts.
            Product::factory()->forSite($this->site)->confirmed()->create();
            Product::factory()->forSite($this->site)->create(['status' => Product::STATUS_DRAFT]);

            // Two leads on this shop.
            EndUser::factory()->forSite($this->site)->count(2)->create();
        });

        // A second account with its own shop + data — must never appear in A's KPIs.
        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create();
        Tenant::run($accountB->id, function () use ($siteB): void {
            Product::factory()->forSite($siteB)->confirmed()->count(5)->create();
            EndUser::factory()->forSite($siteB)->count(9)->create();
        });

        Tenant::run($this->accountA->id, function (): void {
            $page = Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])->assertOk();

            $kpis = collect($page->instance()->kpis())->keyBy('label');

            // Confirmed products = 1 (the draft is excluded; account B's 5 never leak).
            $this->assertSame('1', $kpis['sites.hub.kpi.products']['value'] ?? null);
            // Registered users (leads) = 2 (account B's 9 never leak).
            $this->assertSame('2', $kpis['sites.hub.kpi.leads']['value'] ?? null);
        });
    }

    public function test_hub_renders_a_recent_activity_event_for_this_shop(): void
    {
        Tenant::run($this->accountA->id, function (): void {
            // Record a shop-scoped activity event (site_id set) — the strip surfaces it.
            app(ActivityRecorder::class)->record(
                kind: ActivityEvent::KIND_SITE_SETTINGS_UPDATED,
                siteId: (int) $this->site->id,
                actor: ActivityEvent::ACTOR_MERCHANT,
            );

            Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])
                ->assertOk()
                ->assertSee(__('activity.kind.site_settings_updated'));
        });
    }

    public function test_hub_404s_on_a_foreign_account_shop(): void
    {
        $accountB = Account::factory()->create();
        $foreign = Site::factory()->forAccount($accountB)->create();

        $this->expectException(ModelNotFoundException::class);

        Tenant::run($this->accountA->id, function () use ($foreign): void {
            // Active shop is the merchant's OWN; a hand-crafted URL for another account's
            // shop resolves through the account-scoped record binding to ModelNotFound.
            Livewire::test(ViewSite::class, ['record' => $foreign->getRouteKey()]);
        });
    }
}
