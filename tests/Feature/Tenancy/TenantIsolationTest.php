<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\CrossTenantWriteException;
use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The tenant-isolation gate. Proves the BelongsToAccount global scope isolates
 * accounts and that no tenant context leaks. This file is the release blocker;
 * keep it obvious for the saas-credits-billing isolation audit.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_a_cannot_read_account_b_sites(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        Site::factory()->forAccount($accountA)->count(2)->create();
        Site::factory()->forAccount($accountB)->count(3)->create();

        // Bound as A, the global scope returns only A's sites.
        $seenAsA = Tenant::run($accountA, fn () => Site::all());
        $this->assertCount(2, $seenAsA);
        $this->assertTrue($seenAsA->every(fn (Site $s) => $s->account_id === $accountA->id));

        // Bound as B, the global scope returns only B's sites.
        $seenAsB = Tenant::run($accountB, fn () => Site::all());
        $this->assertCount(3, $seenAsB);
        $this->assertTrue($seenAsB->every(fn (Site $s) => $s->account_id === $accountB->id));

        // A cannot fetch a specific B site by id even with an explicit query.
        $bSiteId = $seenAsB->first()->id;
        $crossRead = Tenant::run($accountA, fn () => Site::where('id', $bSiteId)->first());
        $this->assertNull($crossRead);
    }

    public function test_unbound_query_fails_closed_returns_nothing(): void
    {
        $account = Account::factory()->create();
        Site::factory()->forAccount($account)->count(2)->create();

        // No tenant bound: the scope fails closed (sentinel) -> empty set,
        // never a silent leak of all accounts' rows.
        Tenant::clear();
        $this->assertCount(0, Site::all());
        $this->assertNull(Site::first());
    }

    public function test_creating_a_site_auto_fills_account_id_from_bound_tenant(): void
    {
        $account = Account::factory()->create();

        $site = Tenant::run($account, fn () => Site::create(['name' => 'Auto Store']));

        $this->assertSame($account->id, $site->account_id);
    }

    public function test_creating_a_tenant_model_without_a_bound_tenant_throws(): void
    {
        $this->expectException(RuntimeException::class);

        Tenant::clear();
        Site::create(['name' => 'No Tenant Store']); // no account_id, no bound tenant
    }

    public function test_creating_with_a_foreign_account_id_while_bound_throws(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        $this->expectException(CrossTenantWriteException::class);

        // account_id is NOT mass-assignable (mass-assignment would silently
        // drop it). The real cross-tenant vector is a DIRECT attribute set —
        // exactly how future tenant models could pass a foreign id. Bound as
        // A, directly stamping B's id must throw.
        Tenant::run($accountA, function () use ($accountB) {
            $site = new Site;
            $site->name = 'Foreign Store';
            $site->account_id = $accountB->id;
            $site->save();
        });
    }

    public function test_cross_tenant_write_is_not_persisted(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        try {
            Tenant::run($accountA, function () use ($accountB) {
                $site = new Site;
                $site->name = 'Foreign Store';
                $site->account_id = $accountB->id;
                $site->save();
            });
        } catch (CrossTenantWriteException) {
            // expected — the row must never have been written.
        }

        $this->assertSame(0, \DB::table('sites')->where('name', 'Foreign Store')->count());
    }

    public function test_explicit_matching_account_id_while_bound_is_allowed(): void
    {
        $account = Account::factory()->create();

        // Explicit id that MATCHES the bound tenant is fine (no exception).
        $site = Tenant::run($account, function () use ($account) {
            $s = new Site;
            $s->name = 'Matching Store';
            $s->account_id = $account->id;
            $s->save();

            return $s;
        });

        $this->assertSame($account->id, $site->account_id);
    }

    public function test_account_id_is_not_mass_assignable(): void
    {
        // Defense in depth: even if someone passes account_id through create(),
        // it is dropped by $guarded (not in $fillable), so it can never be set
        // from request input.
        $account = Account::factory()->create();

        $this->assertFalse((new Site)->isFillable('account_id'));

        // Bound as the account, mass-assigning a foreign id is simply ignored
        // and the row is stamped with the bound tenant.
        $other = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Mass Assign Store',
            'account_id' => $other->id,
        ]));

        $this->assertSame($account->id, $site->account_id);
    }

    public function test_tenant_run_clears_context_even_when_callback_throws(): void
    {
        $account = Account::factory()->create();

        $this->assertFalse(Tenant::check());

        try {
            Tenant::run($account, function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        // finally must have cleared the binding back to none.
        $this->assertFalse(Tenant::check());
        $this->assertNull(Tenant::current());
    }

    public function test_nested_tenant_run_restores_outer_context(): void
    {
        $outer = Account::factory()->create();
        $inner = Account::factory()->create();

        Tenant::run($outer, function () use ($outer, $inner) {
            $this->assertSame($outer->id, Tenant::current());

            Tenant::run($inner, function () use ($inner) {
                $this->assertSame($inner->id, Tenant::current());
            });

            // Inner scope restored the outer tenant, not "no tenant".
            $this->assertSame($outer->id, Tenant::current());
        });

        $this->assertFalse(Tenant::check());
    }
}
