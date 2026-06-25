<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\ProvisionSiteJob;
use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The TS-TENANCY-001 reproduction harness.
 *
 * Runs an Account-A job then an Account-B job consecutively in the SAME process
 * (simulating one long-lived Horizon worker) and asserts B's work lands on
 * account_id = B with no stale A context. This failed before the Tenant::run()
 * `finally`-clear fix and the explicit-constructor-account_id rule.
 */
class TenantLeakBetweenJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_back_to_back_two_account_jobs_do_not_leak_tenant(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        // No tenant bound at the start — a fresh worker.
        $this->assertFalse(Tenant::check());

        // Job for A runs first, then job for B on the same process.
        (new ProvisionSiteJob($accountA->id, 'A Store'))->handle();

        // After A's job, the context must be cleared (finally), so a job that
        // wrongly relied on ambient state would have NO tenant — not A's.
        $this->assertFalse(Tenant::check(), 'Tenant leaked after Account A job finished');

        (new ProvisionSiteJob($accountB->id, 'B Store'))->handle();

        $this->assertFalse(Tenant::check(), 'Tenant leaked after Account B job finished');

        // B's site must be stamped with account_id = B (not A).
        $bSite = Tenant::run($accountB, fn () => Site::where('name', 'B Store')->first());
        $this->assertNotNull($bSite);
        $this->assertSame($accountB->id, $bSite->account_id);

        // A's site must be stamped with account_id = A.
        $aSite = Tenant::run($accountA, fn () => Site::where('name', 'A Store')->first());
        $this->assertNotNull($aSite);
        $this->assertSame($accountA->id, $aSite->account_id);

        // Cross-check at the raw DB level: exactly one site per account, no leak.
        $this->assertSame(1, \DB::table('sites')->where('account_id', $accountA->id)->count());
        $this->assertSame(1, \DB::table('sites')->where('account_id', $accountB->id)->count());
        $this->assertSame(0, \DB::table('sites')->where('account_id', $accountA->id)->where('name', 'B Store')->count());
    }

    public function test_job_handle_binds_then_clears_the_tenant(): void
    {
        $account = Account::factory()->create();

        $this->assertFalse(Tenant::check());
        (new ProvisionSiteJob($account->id, 'Bind Store'))->handle();
        // The job's Tenant::run() cleared the binding in finally.
        $this->assertFalse(Tenant::check());
    }
}
