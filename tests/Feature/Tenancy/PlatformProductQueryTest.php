<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Platform\PlatformProductQuery;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Platform-admin cross-account Product read seam (the scan review/confirm + setup-status
 * surfaces). Product IS BelongsToAccount, so the Super-Admin control plane needs the ONE
 * sanctioned global-scope bypass to review every account's scanned products. This proves
 * PlatformProductQuery returns products ACROSS accounts for a super-admin, FAILS LOUD
 * (typed exception) for any non-super-admin, and stays within the audited seam allow-list.
 */
class PlatformProductQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_reads_a_sites_products_across_accounts(): void
    {
        [$siteA] = $this->draftProductOnNewAccount();
        [$siteB] = $this->draftProductOnNewAccount();

        $this->actingAs(User::factory()->superAdmin()->create());

        // Each site's products resolve through the seam even though no tenant is bound.
        $this->assertCount(1, PlatformProductQuery::forSite((int) $siteA->getKey())->get());
        $this->assertCount(1, PlatformProductQuery::forSite((int) $siteB->getKey())->get());

        // forSite scopes to the one site only (never the other account's products).
        $aProducts = PlatformProductQuery::forSite((int) $siteA->getKey())->get();
        $this->assertTrue($aProducts->every(fn (Product $p): bool => (int) $p->site_id === (int) $siteA->getKey()));
    }

    public function test_find_with_variants_loads_cross_account_with_variants(): void
    {
        [, $product] = $this->draftProductOnNewAccount(withVariant: true);

        $this->actingAs(User::factory()->superAdmin()->create());

        $loaded = PlatformProductQuery::findWithVariants((int) $product->getKey());

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->relationLoaded('variants'));
        $this->assertCount(1, $loaded->variants);
    }

    public function test_count_for_site_with_status_reflects_confirmed_products(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        Product::factory()->forSite($site)->create(['status' => Product::STATUS_DRAFT]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(0, PlatformProductQuery::countForSiteWithStatus((int) $site->getKey(), Product::STATUS_CONFIRMED));

        Product::factory()->forSite($site)->create(['status' => Product::STATUS_CONFIRMED, 'confirmed_at' => now()]);

        $this->assertSame(1, PlatformProductQuery::countForSiteWithStatus((int) $site->getKey(), Product::STATUS_CONFIRMED));
    }

    public function test_a_merchant_cannot_use_the_seam_it_fails_loud(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $this->actingAs(User::factory()->forAccount($account)->create());

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformProductQuery::forSite((int) $site->getKey());
    }

    public function test_an_unauthenticated_caller_cannot_use_the_seam(): void
    {
        Auth::logout();

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformProductQuery::all();
    }

    public function test_the_seam_is_unusable_even_when_a_tenant_is_bound_by_a_merchant(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        // Being inside a Tenant::run() (a merchant request) does NOT unlock the platform
        // seam — it is gated on the AUTH super-admin flag, not the bind.
        Tenant::run($account, function (): void {
            $this->expectException(PlatformAccessRequiredException::class);
            PlatformProductQuery::all();
        });
    }

    /**
     * A draft product on a brand-new account+site (coherent chain, account-aligned).
     *
     * @return array{0: Site, 1: Product}
     */
    private function draftProductOnNewAccount(bool $withVariant = false): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $product = Product::factory()->forSite($site)->create(['status' => Product::STATUS_DRAFT]);

        if ($withVariant) {
            ProductVariant::factory()->forProduct($product)->create();
        }

        return [$site, $product];
    }
}
