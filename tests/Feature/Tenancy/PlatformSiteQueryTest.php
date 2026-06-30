<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Platform\PlatformSiteQuery;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Platform-admin cross-account Site read seam (Phase 8 Wave-2 seam 2).
 *
 * Site IS BelongsToAccount, so the Super-Admin control plane needs the ONE sanctioned
 * global-scope bypass to list every account's sites. This proves PlatformSiteQuery returns
 * sites ACROSS accounts for a super-admin, FAILS LOUD (typed exception) for any non-super-
 * admin, and is the ONLY new withoutGlobalScope() bypass in product code.
 */
class PlatformSiteQueryTest extends TestCase
{
    use RefreshDatabase;

    /** The app/ directory holds product code; the seams live here. */
    private const APP_DIR = 'app';

    /**
     * The audited platform-admin seams are the ONLY product-code files allowed to call
     * withoutGlobalScope(). Each one is guarded by PlatformGuard (super-admin only).
     */
    private const SANCTIONED_BYPASS_FILES = [
        'app/Domain/Platform/PlatformActivityQuery.php',
        'app/Domain/Platform/PlatformCreditLedgerQuery.php',
        'app/Domain/Platform/PlatformProductQuery.php',
        'app/Domain/Platform/PlatformSiteQuery.php',
    ];

    public function test_super_admin_lists_sites_across_all_accounts(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        Site::factory()->forAccount($accountA)->count(2)->create();
        Site::factory()->forAccount($accountB)->count(3)->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        $all = PlatformSiteQuery::all()->get();

        $this->assertCount(5, $all);
        $this->assertEqualsCanonicalizing(
            [$accountA->id, $accountB->id],
            $all->pluck('account_id')->unique()->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_with_account_eager_loads_the_owning_account_across_tenants(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        Site::factory()->forAccount($accountA)->create();
        Site::factory()->forAccount($accountB)->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        $all = PlatformSiteQuery::withAccount()->get();

        $this->assertCount(2, $all);
        $this->assertTrue($all->every(fn (Site $s) => $s->relationLoaded('account') && $s->account !== null));
    }

    public function test_a_merchant_cannot_use_the_seam_it_fails_loud(): void
    {
        $account = Account::factory()->create();
        Site::factory()->forAccount($account)->create();

        $this->actingAs(User::factory()->forAccount($account)->create());

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformSiteQuery::all();
    }

    public function test_an_unauthenticated_caller_cannot_use_the_seam(): void
    {
        Auth::logout();

        $this->expectException(PlatformAccessRequiredException::class);
        PlatformSiteQuery::all();
    }

    public function test_the_seam_is_unusable_even_when_a_tenant_is_bound_by_a_merchant(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        // Being inside a Tenant::run() (a merchant request) does NOT unlock the
        // platform seam — it is gated on the AUTH super-admin flag, not the bind.
        Tenant::run($account, function () {
            $this->expectException(PlatformAccessRequiredException::class);
            PlatformSiteQuery::all();
        });
    }

    public function test_platform_site_query_is_the_only_new_withoutglobalscope_bypass(): void
    {
        $root = dirname(__DIR__, 3); // tests/Feature/Tenancy -> project root
        $appDir = $root.DIRECTORY_SEPARATOR.self::APP_DIR;

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Match an actual CALL to withoutGlobalScope(s), not a comment mentioning it.
            if (preg_match('/->\s*withoutGlobalScope[s]?\s*\(/', $contents) !== 1) {
                continue;
            }

            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $offenders[] = str_replace('\\', '/', $relative);
        }

        sort($offenders);

        // The ONLY product-code files allowed to call the bypass are the audited seams.
        $this->assertSame(
            self::SANCTIONED_BYPASS_FILES,
            $offenders,
            'A new withoutGlobalScope() bypass appeared outside the audited platform seams: '
                .implode(', ', $offenders),
        );
    }
}
