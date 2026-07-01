<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Leads\LeadAttemptHistory;
use App\Filament\Merchant\Resources\EndUserResource\Pages\ViewEndUser;
use App\Filament\Merchant\Resources\SiteResource\Pages\ViewSite;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 8 Wave-2 isolation re-audit — RECORD-BOUND merchant Filament surfaces.
 *
 * Every record-bound merchant page resolves its {record} through the resource's
 * getEloquentQuery() (Filament's resolveRecordRouteBinding), which honours the
 * BelongsToAccount global scope. So a merchant of Account A who hand-crafts a URL
 * carrying Account B's id must hit ModelNotFoundException (a 404 / fail-closed),
 * NEVER render B's row. This proves the contract for ViewSite (site hub / embed /
 * regenerate) and ViewEndUser (lead card / attempt history) — the Wave-2 record
 * pages a foreign-id probe could target. No manual where(account_id), no
 * withoutGlobalScopes() — the global scope alone is the boundary.
 *
 * Release-blocker class: a green test here that does NOT go red when the scope is
 * removed is theater; the boundary asserted is the scope, so removing the trait
 * would make these 404s become reads (the suite-wide fail-closed tests cover that).
 */
class MerchantResourceRecordIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Account $accountA;

    private Account $accountB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountA = Account::factory()->create();
        $this->accountB = Account::factory()->create();
        $this->ownerA = User::factory()->forAccount($this->accountA)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($this->ownerA);
    }

    /** Run the body with Account A's tenant bound, as BindMerchantAccount would. */
    private function asMerchantA(callable $body): mixed
    {
        return Tenant::run($this->accountA->id, $body);
    }

    /** A site owned by Account B (the foreign account A must never reach). */
    private function foreignSite(): Site
    {
        return Site::factory()->forAccount($this->accountB)->create();
    }

    /** A lead owned by Account B (foreign). */
    private function foreignLead(): EndUser
    {
        $site = $this->foreignSite();

        return Tenant::run($this->accountB, fn () => EndUser::factory()->forSite($site)->create());
    }

    public function test_view_site_hub_404s_on_a_foreign_account_site(): void
    {
        $foreign = $this->foreignSite();

        // Bound as A, mounting the site hub with B's record id resolves through the
        // account-scoped resource query -> null -> ModelNotFoundException (404).
        $this->expectException(ModelNotFoundException::class);

        $this->asMerchantA(fn () => Livewire::test(
            ViewSite::class,
            ['record' => $foreign->getRouteKey()],
        ));
    }

    public function test_view_end_user_lead_card_404s_on_a_foreign_account_lead(): void
    {
        $foreign = $this->foreignLead();

        // Bound as A, mounting the lead card with B's lead id 404s through the
        // account-scoped resource query — B's PII / attempt history never renders.
        $this->expectException(ModelNotFoundException::class);

        $this->asMerchantA(fn () => Livewire::test(
            ViewEndUser::class,
            ['record' => $foreign->getRouteKey()],
        ));
    }

    public function test_own_site_hub_renders_for_the_owner(): void
    {
        // Control: A's OWN site renders fine — the 404 above is isolation, not a
        // blanket failure (a meaningful test must show the happy path works).
        $ownSite = Site::factory()->forAccount($this->accountA)->create();
        Filament::setTenant($ownSite); // shop-centric panel: bind the active shop

        $this->asMerchantA(function () use ($ownSite): void {
            Livewire::test(ViewSite::class, ['record' => $ownSite->getRouteKey()])
                ->assertOk()
                ->assertSee($ownSite->site_key);
        });
    }

    public function test_own_lead_card_renders_for_the_owner(): void
    {
        $ownSite = Site::factory()->forAccount($this->accountA)->create();
        Filament::setTenant($ownSite); // shop-centric panel: bind the active shop
        $ownLead = $this->asMerchantA(fn () => EndUser::factory()->forSite($ownSite)->create([
            'full_name' => 'Owner A Lead',
        ]));

        $this->asMerchantA(function () use ($ownLead): void {
            Livewire::test(ViewEndUser::class, ['record' => $ownLead->getRouteKey()])
                ->assertOk()
                ->assertSee('Owner A Lead');
        });
    }

    public function test_lead_attempt_history_is_isolated_to_the_leads_own_account(): void
    {
        // Account B's lead has a succeeded + a failed generation. Account A's lead has
        // none. The attempt history for A's lead must NOT surface ANY of B's attempts —
        // LeadAttemptHistory binds Tenant::run($endUser->account_id), so it is scoped to
        // the lead's OWN account, not the caller's.
        $siteB = $this->foreignSite();
        [$leadB] = $this->seedLeadWithAttempts($this->accountB, $siteB);

        $siteA = Site::factory()->forAccount($this->accountA)->create();
        $leadA = $this->asMerchantA(fn () => EndUser::factory()->forSite($siteA)->create());

        $historyA = app(LeadAttemptHistory::class)->for($leadA);
        $historyB = app(LeadAttemptHistory::class)->for($leadB);

        // A's lead has no attempts; B's has two. Neither history can see the other's.
        $this->assertCount(0, $historyA);
        $this->assertCount(2, $historyB);

        // Cross-probe: every generation id in B's history belongs to account B only.
        $bGenAccountIds = Tenant::run($this->accountB, fn () => Generation::query()
            ->whereIn('id', $historyB->pluck('generationId')->all())
            ->pluck('account_id')->unique()->all());
        $this->assertSame([$this->accountB->id], array_map('intval', $bGenAccountIds));
    }

    /**
     * Seed a lead with one succeeded + one failed generation under $account/$site.
     *
     * @return array{0: EndUser}
     */
    private function seedLeadWithAttempts(Account $account, Site $site): array
    {
        $lead = Tenant::run($account, function () use ($account, $site) {
            $lead = EndUser::factory()->forSite($site)->create();
            $product = Product::factory()->forSite($site)->confirmed()->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();

            Generation::factory()->forContext($lead, $product, $variant, 'crq-ok-'.$account->id)
                ->create(['status' => Generation::STATUS_SUCCEEDED]);
            Generation::factory()->forContext($lead, $product, $variant, 'crq-fail-'.$account->id)
                ->create(['status' => Generation::STATUS_FAILED, 'failure_code' => 'ai_call_failed']);

            return $lead;
        });

        return [$lead];
    }
}
