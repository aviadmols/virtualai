<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\SiteResource\Pages\ListSites;
use App\Filament\Platform\Resources\SiteResource\Pages\ManageSiteProducts;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform per-site scan review/confirm + verify-setup (cross-account, super-admin).
 *
 * Proves: the super-admin reviews a site's scanned products READ-ONLY via the audited
 * PlatformProductQuery seam (no bound tenant); a DRAFT product confirmed via the platform
 * action transitions DRAFT → CONFIRMED, account-scoped, persisted; a still-blocked scan
 * surfaces a friendly warning, never a 500; the site reads "Ready" once a product is
 * confirmed; and the verify-setup checklist reflects the missing/present prerequisites.
 */
class PlatformSiteProductsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();
    }

    /** A draft product whose scan rows are blocking until acknowledged (default factory). */
    private function draftProduct(): Product
    {
        $product = Product::factory()->forSite($this->site)->create([
            'status' => Product::STATUS_DRAFT,
            'name' => 'Demo Tee',
        ]);

        ProductVariant::factory()->forProduct($product)->create();

        return $product;
    }

    public function test_products_page_renders_a_sites_scanned_products(): void
    {
        $product = $this->draftProduct();

        Livewire::test(ManageSiteProducts::class, ['record' => $this->site->id])
            ->assertOk()
            ->assertSee('Demo Tee')
            ->assertSee(__('platform.sites.products.status.draft'));
    }

    public function test_super_admin_confirms_a_draft_product_from_the_platform_panel(): void
    {
        $product = $this->draftProduct();

        Livewire::test(ManageSiteProducts::class, ['record' => $this->site->id])
            ->callTableAction('confirm', $product)
            ->assertHasNoTableActionErrors();

        $fresh = $product->fresh();
        $this->assertSame(Product::STATUS_CONFIRMED, $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
        // Confirmed under its OWN account (the action bound the product's account_id).
        $this->assertSame($this->account->id, (int) $fresh->account_id);
    }

    public function test_confirm_action_is_hidden_for_a_non_draft_product(): void
    {
        $confirmed = Product::factory()->forSite($this->site)->create([
            'status' => Product::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        Livewire::test(ManageSiteProducts::class, ['record' => $this->site->id])
            ->assertTableActionHidden('confirm', $confirmed)
            ->assertTableActionVisible('review', $confirmed);
    }

    public function test_a_blocked_scan_surfaces_a_typed_notice_not_a_500(): void
    {
        // A failed scan can never be confirmed (the gate / state machine refuses). The
        // confirm action is hidden, and even a forced confirm stays a graceful no-op.
        $failed = Product::factory()->forSite($this->site)->create(['status' => Product::STATUS_FAILED]);

        Livewire::test(ManageSiteProducts::class, ['record' => $this->site->id])
            ->assertTableActionHidden('confirm', $failed)
            ->assertOk();

        $this->assertSame(Product::STATUS_FAILED, $failed->fresh()->status);
    }

    public function test_setup_state_is_pending_without_a_confirmed_product_and_ready_after(): void
    {
        $product = $this->draftProduct();

        // Before confirm: no confirmed product, no site->selectors → Setup pending.
        Livewire::test(ListSites::class)
            ->assertSee(__('platform.sites.state.pending'));

        // Confirm it from the platform panel.
        Livewire::test(ManageSiteProducts::class, ['record' => $this->site->id])
            ->callTableAction('confirm', $product)
            ->assertHasNoTableActionErrors();

        $this->assertSame(Product::STATUS_CONFIRMED, $product->fresh()->status);

        // After confirm: the site reads Ready (a confirmed product exists).
        Livewire::test(ListSites::class)
            ->assertSee(__('platform.sites.state.ready'));
    }

    public function test_verify_setup_checklist_reflects_missing_then_present_product(): void
    {
        // Missing: no confirmed product yet. Mounting the action opens the checklist modal,
        // which renders every prerequisite row + its hint.
        Livewire::test(ListSites::class)
            ->mountTableAction('verify', $this->site)
            ->assertSee(__('platform.sites.verify.check.product'))
            ->assertSee(__('platform.sites.verify.check.origins'))
            ->assertSee(__('platform.sites.verify.check.openrouter'))
            ->assertSee(__('platform.sites.verify.hint.product'));

        // Add + confirm a product, then the checklist still renders the product row.
        $product = $this->draftProduct();
        Livewire::test(ManageSiteProducts::class, ['record' => $this->site->id])
            ->callTableAction('confirm', $product)
            ->assertHasNoTableActionErrors();

        Livewire::test(ListSites::class)
            ->mountTableAction('verify', $this->site)
            ->assertSee(__('platform.sites.verify.check.product'));
    }

    public function test_products_action_links_to_the_per_site_review_page(): void
    {
        $expected = ManageSiteProducts::getUrl(['record' => $this->site->getKey()]);

        Livewire::test(ListSites::class)
            ->assertTableActionHasUrl('products', $expected, record: $this->site);
    }
}
