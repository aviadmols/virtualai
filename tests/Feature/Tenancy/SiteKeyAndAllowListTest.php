<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the site_key NULL-not-empty rule (empty string collides under the
 * unique index) and asserts the global non-tenant allow-list is explicit.
 */
class SiteKeyAndAllowListTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_key_is_generated_unique_on_create(): void
    {
        $account = Account::factory()->create();

        [$a, $b] = Tenant::run($account, fn () => [
            Site::create(['name' => 'One']),
            Site::create(['name' => 'Two']),
        ]);

        $this->assertNotNull($a->site_key);
        $this->assertNotNull($b->site_key);
        $this->assertNotSame($a->site_key, $b->site_key);
    }

    public function test_empty_site_key_never_persists_as_empty_string_and_does_not_collide(): void
    {
        $account = Account::factory()->create();

        // Two sites both supplied an empty-string site_key. The guard ensures
        // neither stores '' (which would collide under the unique index): each
        // is coerced away from '' and a unique key is generated instead.
        $first = Tenant::run($account, fn () => Site::create(['name' => 'Empty One', 'site_key' => '']));
        $second = Tenant::run($account, fn () => Site::create(['name' => 'Empty Two', 'site_key' => '']));

        $rawFirst = DB::table('sites')->where('id', $first->id)->value('site_key');
        $rawSecond = DB::table('sites')->where('id', $second->id)->value('site_key');

        // The load-bearing guarantee: '' never reaches the column.
        $this->assertNotSame('', $rawFirst);
        $this->assertNotSame('', $rawSecond);

        // And the two empty inputs did not collide on the unique index.
        $this->assertNotSame($rawFirst, $rawSecond);
    }

    public function test_null_site_key_stays_null_when_generation_is_skipped(): void
    {
        // Directly exercise the saving guard without the creating-generation
        // path: an empty string is coerced to NULL (never '') at the DB layer.
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'Keyed']));

        // Force the key to '' on an existing row and persist.
        $site->site_key = '';
        $site->save();

        $raw = DB::table('sites')->where('id', $site->id)->value('site_key');
        $this->assertNull($raw, 'empty-string site_key must persist as NULL, never ""');
    }

    public function test_global_models_allow_list_marks_user_global_and_site_tenant(): void
    {
        // User is on the allow-list (global, not account-scoped).
        $this->assertTrue(GlobalModels::isGlobal(User::class));

        // Site is NOT on the allow-list (it is tenant-owned).
        $this->assertFalse(GlobalModels::isGlobal(Site::class));

        // The platform control-plane catalogs are pre-registered for the audit.
        $this->assertTrue(GlobalModels::isGlobal('App\\Models\\AiModel'));
        $this->assertTrue(GlobalModels::isGlobal('App\\Models\\AiOperation'));
        $this->assertTrue(GlobalModels::isGlobal('App\\Models\\Prompt'));
    }

    public function test_site_uses_belongs_to_account_global_scope(): void
    {
        // Structural assertion: Site is tenant-scoped; querying with no tenant
        // bound returns nothing (fail-closed) rather than all rows.
        $account = Account::factory()->create();
        Site::factory()->forAccount($account)->create();

        Tenant::clear();
        $this->assertCount(0, Site::all());
        $this->assertFalse(GlobalModels::isGlobal(Site::class));
    }

    public function test_user_for_account_scope_lists_only_that_accounts_owners(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        User::factory()->forAccount($accountA)->count(2)->create();
        User::factory()->forAccount($accountB)->count(3)->create();
        User::factory()->superAdmin()->create(); // global, belongs to no account

        // User is global (no tenant global scope), so forAccount() is the
        // explicit account-scoping tool the merchant panel uses.
        $this->assertCount(2, User::forAccount($accountA)->get());
        $this->assertCount(3, User::forAccount($accountB)->get());
        $this->assertCount(2, User::forAccount($accountA->id)->get()); // accepts an id too
    }
}
