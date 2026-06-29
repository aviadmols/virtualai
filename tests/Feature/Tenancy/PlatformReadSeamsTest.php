<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Platform\PlatformActivityQuery;
use App\Domain\Platform\PlatformCreditLedgerQuery;
use App\Domain\Platform\PlatformSiteQuery;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Database\Factories\CreditLedgerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The two audited platform read seams added in Phase 8 Wave-2 (GAP-P1):
 * PlatformCreditLedgerQuery (platform credits view) and PlatformActivityQuery
 * (platform observability view). Both mirror PlatformSiteQuery EXACTLY: a confirmed
 * super-admin reads ACROSS accounts; any non-super-admin (or unauthenticated, or a
 * merchant with a bound tenant) FAILS LOUD with the typed exception; the owning account
 * is surfaced for display.
 */
class PlatformReadSeamsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed N ledger rows for an account. CreditLedger has no HasFactory (it is
     * append-only; only CreditLedgerService inserts in product code), so the isolation
     * factory is instantiated directly — its sole sanctioned use (per the factory docblock).
     */
    private function seedLedgerRows(Account $account, int $count): void
    {
        CreditLedgerFactory::new()->forAccount($account)->count($count)->create();
    }

    /** Seed N activity events for an account through the real writer (production shape). */
    private function seedActivityEvents(Account $account, int $count): void
    {
        Tenant::run($account, function () use ($account, $count): void {
            $recorder = app(ActivityRecorder::class);

            for ($i = 0; $i < $count; $i++) {
                $recorder->record(
                    kind: ActivityEvent::KIND_CREDIT_GRANT,
                    subject: $account,
                    details: ['seq' => $i],
                );
            }
        });
    }

    // === PlatformCreditLedgerQuery ===

    public function test_super_admin_reads_ledger_rows_across_all_accounts(): void
    {
        $accountA = Account::factory()->create(); // each account also gets a $5 opening grant
        $accountB = Account::factory()->create();
        $this->seedLedgerRows($accountA, 2);
        $this->seedLedgerRows($accountB, 3);

        $this->actingAs(User::factory()->superAdmin()->create());

        $all = PlatformCreditLedgerQuery::all()->get();

        // 2 + 3 seeded rows are visible across both accounts (each account also carries
        // its one opening-grant row, so assert >= the seeded 5).
        $this->assertGreaterThanOrEqual(5, $all->count());
        $this->assertEqualsCanonicalizing(
            [$accountA->id, $accountB->id],
            $all->pluck('account_id')->unique()->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_ledger_seam_with_account_eager_loads_owning_account(): void
    {
        $account = Account::factory()->create();
        $this->seedLedgerRows($account, 1);

        $this->actingAs(User::factory()->superAdmin()->create());

        $rows = PlatformCreditLedgerQuery::withAccount()->get();

        $this->assertTrue($rows->every(fn (CreditLedger $r) => $r->relationLoaded('account') && $r->account !== null));
    }

    public function test_ledger_seam_for_account_scopes_to_one_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->seedLedgerRows($accountA, 2);
        $this->seedLedgerRows($accountB, 4);

        $this->actingAs(User::factory()->superAdmin()->create());

        $rows = PlatformCreditLedgerQuery::forAccount($accountA->id)->get();

        $this->assertTrue($rows->every(fn (CreditLedger $r) => (int) $r->account_id === $accountA->id));
    }

    public function test_ledger_seam_fails_loud_for_a_merchant(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformCreditLedgerQuery::all();
    }

    public function test_ledger_seam_fails_loud_unauthenticated(): void
    {
        Auth::logout();

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformCreditLedgerQuery::all();
    }

    public function test_ledger_seam_unusable_even_with_a_bound_tenant(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        Tenant::run($account, function () {
            $this->expectException(PlatformAccessRequiredException::class);
            PlatformCreditLedgerQuery::all();
        });
    }

    // === PlatformActivityQuery ===

    public function test_super_admin_reads_activity_across_all_accounts(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->seedActivityEvents($accountA, 2);
        $this->seedActivityEvents($accountB, 3);

        $this->actingAs(User::factory()->superAdmin()->create());

        $all = PlatformActivityQuery::all()->get();

        // Both accounts' events are present and only those two accounts appear (each
        // account also carries its one opening-grant event, so assert >= the seeded 5).
        $this->assertGreaterThanOrEqual(5, $all->count());
        $this->assertEqualsCanonicalizing(
            [$accountA->id, $accountB->id],
            $all->pluck('account_id')->unique()->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_activity_seam_with_account_eager_loads_owning_account(): void
    {
        $account = Account::factory()->create();
        $this->seedActivityEvents($account, 1);

        $this->actingAs(User::factory()->superAdmin()->create());

        $rows = PlatformActivityQuery::withAccount()->get();

        $this->assertTrue($rows->every(fn (ActivityEvent $e) => $e->relationLoaded('account') && $e->account !== null));
    }

    public function test_activity_seam_for_account_scopes_to_one_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->seedActivityEvents($accountA, 2);
        $this->seedActivityEvents($accountB, 4);

        $this->actingAs(User::factory()->superAdmin()->create());

        $rows = PlatformActivityQuery::forAccount($accountA->id)->get();

        // Only account A's events come back (B's 4 never appear), and at least the 2 seeded.
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertTrue($rows->every(fn (ActivityEvent $e) => (int) $e->account_id === $accountA->id));
    }

    public function test_activity_seam_fails_loud_for_a_merchant(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformActivityQuery::all();
    }

    public function test_activity_seam_fails_loud_unauthenticated(): void
    {
        Auth::logout();

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformActivityQuery::all();
    }

    public function test_sites_credits_and_activity_seams_all_share_the_super_admin_guard(): void
    {
        // A merchant user is denied by all three seams identically (one shared guard).
        $account = Account::factory()->create();
        Site::factory()->forAccount($account)->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        foreach ([
            fn () => PlatformSiteQuery::all(),
            fn () => PlatformCreditLedgerQuery::all(),
            fn () => PlatformActivityQuery::all(),
        ] as $seam) {
            try {
                $seam();
                $this->fail('Expected the platform seam to deny a merchant.');
            } catch (PlatformAccessRequiredException) {
                $this->assertTrue(true);
            }
        }
    }
}
