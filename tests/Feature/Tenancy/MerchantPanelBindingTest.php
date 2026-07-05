<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\BindMerchantAccount;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Merchant-panel account-binding gate (Phase 8 Wave-2 seam 1).
 *
 * Proves the BindMerchantAccount middleware binds the authenticated account-owner's
 * Tenant for the whole request so every BelongsToAccount query a merchant resource runs
 * is auto-scoped to that ONE account — Account B's sites/products/generations/leads never
 * appear — and that with no auth user / no account it FAILS CLOSED (binds nothing → the
 * global scope returns an empty set, never B's data). A release-blocker file; kept obvious
 * for the saas-credits-billing isolation audit.
 */
class MerchantPanelBindingTest extends TestCase
{
    use RefreshDatabase;

    private const CRQ_A = 'crq_merchant_a';
    private const CRQ_B = 'crq_merchant_b';

    /** Build a full tenant-owned chain (site→product→variant→lead→generation) under one account. */
    private function seedAccountData(Account $account, string $crq): array
    {
        $site = Site::factory()->forAccount($account)->create();
        $product = Product::factory()->forSite($site)->confirmed()->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $lead = EndUser::factory()->forSite($site)->create();
        $generation = Generation::factory()
            ->forContext($lead, $product, $variant, $crq)
            ->create();

        return compact('site', 'product', 'variant', 'lead', 'generation');
    }

    /**
     * Run a payload INSIDE the merchant binding as the given (or no) auth user, and return what the
     * payload observed. The bind is now REQUEST-LIFETIME (it must survive to the Livewire-update
     * component method, which runs after the terminal persistent-middleware pipeline), so this
     * helper clears the binding afterwards to emulate request termination — keeping each case
     * isolated exactly as the app()->terminating() clear does per real request.
     */
    private function throughMiddleware(?User $user, callable $payload): mixed
    {
        $request = Request::create('/merchant', 'GET');
        $request->setUserResolver(static fn () => $user);

        $observed = null;
        try {
            app(BindMerchantAccount::class)->handle(
                $request,
                static function () use ($payload, &$observed): Response {
                    $observed = $payload();

                    return new Response('ok');
                },
            );
        } finally {
            Tenant::clear(); // emulate app()->terminating(): request-lifetime bind cleared at request end
        }

        return $observed;
    }

    public function test_merchant_sees_only_their_own_account_across_every_tenant_model(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->seedAccountData($accountA, self::CRQ_A);
        $this->seedAccountData($accountB, self::CRQ_B);

        $ownerA = User::factory()->forAccount($accountA)->create();

        // Inside the merchant binding, every BelongsToAccount query is scoped to A.
        $seen = $this->throughMiddleware($ownerA, fn () => [
            'sites' => Site::all(),
            'products' => Product::all(),
            'variants' => ProductVariant::all(),
            'leads' => EndUser::all(),
            'generations' => Generation::all(),
            'tenant' => Tenant::current(),
        ]);

        $this->assertSame($accountA->id, $seen['tenant']);

        foreach (['sites', 'products', 'variants', 'leads', 'generations'] as $key) {
            $this->assertCount(1, $seen[$key], "expected exactly A's $key");
            $this->assertTrue(
                $seen[$key]->every(fn ($row) => (int) $row->account_id === $accountA->id),
                "a $key row leaked from another account",
            );
        }
    }

    public function test_binding_survives_the_middleware_next_but_clears_at_request_end(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->forAccount($account)->create();

        $this->assertFalse(Tenant::check());

        // The bind is request-lifetime: it is set while the payload runs (inside $next)...
        $bound = $this->throughMiddleware($owner, fn () => Tenant::current());
        $this->assertSame($account->id, $bound);

        // ...and throughMiddleware's finally (emulating app()->terminating()) clears it, so nothing
        // leaks to the next request.
        $this->assertFalse(Tenant::check());
    }

    public function test_the_bind_is_NOT_cleared_by_the_terminal_middleware_pipeline_returning(): void
    {
        // Regression guard for the write-path bug: on a Livewire update the tenant middleware's
        // $next returns immediately (terminal pipeline). The bind must still be ACTIVE afterwards so
        // the component's Save / action runs account-scoped. Prove handle() leaves it bound (only
        // request-termination clears it), unlike the old run()-scoped bind that cleared on $next.
        $account = Account::factory()->create();
        $owner = User::factory()->forAccount($account)->create();

        $request = Request::create('/merchant', 'GET');
        $request->setUserResolver(static fn () => $owner);

        try {
            app(BindMerchantAccount::class)->handle(
                $request,
                static fn (): Response => new Response('ok'), // terminal $next, like the update pipeline
            );

            // Still bound AFTER handle() returned — this is what makes Save / pick work on updates.
            $this->assertTrue(Tenant::check());
            $this->assertSame($account->id, Tenant::current());
        } finally {
            Tenant::clear();
        }
    }

    public function test_a_stale_binding_is_replaced_not_merged_so_accounts_never_bleed(): void
    {
        // Simulate a previous request leaving a binding behind (e.g. terminating not yet run):
        // the next merchant request must REPLACE it with its own account, never keep the stale one.
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $ownerB = User::factory()->forAccount($accountB)->create();

        Tenant::bindForRequest($accountA->id); // stale binding from a prior request

        $bound = $this->throughMiddleware($ownerB, fn () => Tenant::current());

        $this->assertSame($accountB->id, $bound, 'the stale account A binding must be replaced by B');
        $this->assertFalse(Tenant::check());
    }

    public function test_no_authenticated_user_fails_closed_returns_nothing_not_another_accounts_data(): void
    {
        $accountB = Account::factory()->create();
        $this->seedAccountData($accountB, self::CRQ_B);

        // No auth user → no bind. The fail-closed scope returns an empty set,
        // NEVER account B's rows.
        $seen = $this->throughMiddleware(null, fn () => [
            'sites' => Site::all(),
            'generations' => Generation::all(),
            'tenant' => Tenant::current(),
        ]);

        $this->assertNull($seen['tenant']);
        $this->assertCount(0, $seen['sites']);
        $this->assertCount(0, $seen['generations']);
    }

    public function test_authenticated_user_without_an_account_fails_closed(): void
    {
        $accountB = Account::factory()->create();
        $this->seedAccountData($accountB, self::CRQ_B);

        // A user with no account_id (e.g. a super-admin that slipped past the
        // canAccessPanel gate). The middleware binds nothing → empty set, not B's data.
        $orphan = User::factory()->superAdmin()->create();

        $seen = $this->throughMiddleware($orphan, fn () => [
            'sites' => Site::all(),
            'tenant' => Tenant::current(),
        ]);

        $this->assertNull($seen['tenant']);
        $this->assertCount(0, $seen['sites']);
    }

    public function test_two_merchants_back_to_back_never_cross_contaminate(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->seedAccountData($accountA, self::CRQ_A);
        $this->seedAccountData($accountB, self::CRQ_B);

        $ownerA = User::factory()->forAccount($accountA)->create();
        $ownerB = User::factory()->forAccount($accountB)->create();

        $aSites = $this->throughMiddleware($ownerA, fn () => Site::all());
        // Between requests the binding is cleared; B's request must see only B.
        $bSites = $this->throughMiddleware($ownerB, fn () => Site::all());

        $this->assertCount(1, $aSites);
        $this->assertSame($accountA->id, (int) $aSites->first()->account_id);

        $this->assertCount(1, $bSites);
        $this->assertSame($accountB->id, (int) $bSites->first()->account_id);
    }
}
