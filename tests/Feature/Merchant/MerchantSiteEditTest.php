<?php

namespace Tests\Feature\Merchant;

use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merchant site editing is tenant-safe by the merchant binding.
 *
 * The merchant EditSite page resolves + saves the record under the account bound by
 * BindMerchantAccount, i.e. through the BelongsToAccount global scope. This proves a
 * merchant can edit its OWN site, and that another account's site is invisible
 * (fail-closed) so it can never be resolved — let alone edited — from the wrong account.
 */
class MerchantSiteEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_edit_its_own_site(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create(['name' => 'Old name']);

        Tenant::run($account, function () use ($site): void {
            Site::query()->whereKey($site->id)->firstOrFail()->update(['name' => 'New name']);
        });

        $persisted = Tenant::run($account, fn () => Site::query()->whereKey($site->id)->value('name'));
        $this->assertSame('New name', $persisted);
    }

    public function test_merchant_cannot_resolve_another_accounts_site(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create(['name' => 'B only']);

        // Bound to A, B's site is fail-closed invisible — never resolvable to edit.
        $found = Tenant::run($accountA, fn () => Site::query()->whereKey($siteB->id)->first());
        $this->assertNull($found);

        // And B's site is untouched.
        $name = Tenant::run($accountB, fn () => Site::query()->whereKey($siteB->id)->value('name'));
        $this->assertSame('B only', $name);
    }
}
