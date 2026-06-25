<?php

namespace Tests\Feature\Scan;

use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation for the scanned Product + ProductVariant. Extends the release-
 * blocker isolation harness: a scanned product is account + site scoped and account
 * B can never read account A's products or variants. Keep obvious for the
 * saas-credits-billing isolation audit.
 */
class ProductTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_and_variant_are_not_on_the_global_allow_list(): void
    {
        // Tenant-owned models must NOT be exempt from BelongsToAccount.
        $this->assertFalse(GlobalModels::isGlobal(Product::class));
        $this->assertFalse(GlobalModels::isGlobal(ProductVariant::class));
    }

    public function test_account_b_cannot_read_account_a_products(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();
        $siteB = Site::factory()->forAccount($accountB)->create();

        Product::factory()->forSite($siteA)->count(2)->create();
        Product::factory()->forSite($siteB)->count(3)->create();

        $seenAsA = Tenant::run($accountA, fn () => Product::all());
        $this->assertCount(2, $seenAsA);
        $this->assertTrue($seenAsA->every(fn (Product $p) => $p->account_id === $accountA->id));

        $seenAsB = Tenant::run($accountB, fn () => Product::all());
        $this->assertCount(3, $seenAsB);

        // A cannot fetch a specific B product by id.
        $bProductId = $seenAsB->first()->id;
        $crossRead = Tenant::run($accountA, fn () => Product::where('id', $bProductId)->first());
        $this->assertNull($crossRead);
    }

    public function test_account_b_cannot_read_account_a_variants(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();
        $productA = Product::factory()->forSite($siteA)->create();

        ProductVariant::factory()->forProduct($productA)->count(4)->create();

        $seenAsB = Tenant::run($accountB, fn () => ProductVariant::all());
        $this->assertCount(0, $seenAsB);

        $seenAsA = Tenant::run($accountA, fn () => ProductVariant::all());
        $this->assertCount(4, $seenAsA);
    }

    public function test_unbound_product_query_fails_closed(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        Product::factory()->forSite($site)->count(2)->create();

        Tenant::clear();
        $this->assertCount(0, Product::all());
    }

    public function test_product_is_site_scoped_and_account_scoped(): void
    {
        $account = Account::factory()->create();
        $siteOne = Site::factory()->forAccount($account)->create();
        $siteTwo = Site::factory()->forAccount($account)->create();

        Product::factory()->forSite($siteOne)->count(2)->create();
        Product::factory()->forSite($siteTwo)->count(1)->create();

        // Same account, both sites visible; site_id sub-scopes within the account.
        Tenant::run($account, function () use ($siteOne, $siteTwo) {
            $this->assertSame(2, Product::where('site_id', $siteOne->id)->count());
            $this->assertSame(1, Product::where('site_id', $siteTwo->id)->count());
            $this->assertSame(3, Product::count());
        });
    }
}
