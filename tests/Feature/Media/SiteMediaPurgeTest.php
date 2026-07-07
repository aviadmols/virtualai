<?php

namespace Tests\Feature\Media;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Media\MediaStorage;
use App\Domain\Media\PurgeSiteMediaJob;
use App\Models\Account;
use App\Models\Banner;
use App\Models\CreditLedger;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting a site must leave nothing behind: its DB rows cascade (FK cascadeOnDelete) and its
 * bucket media is purged — and NEVER another site's. Proves the purge is scoped to the ONE
 * site's accounts/{account}/sites/{site}/ prefix, that a delete dispatches the purge, and that
 * the DB cascade removes the site's rows while keeping the account-level ledger + other sites.
 */
class SiteMediaPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    public function test_purge_removes_only_the_target_sites_media(): void
    {
        $accountA = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();
        $siteB = Site::factory()->forAccount($accountA)->create(); // same account, different site
        $accountC = Account::factory()->create();
        $siteC = Site::factory()->forAccount($accountC)->create();

        $media = app(MediaStorage::class);
        $disk = Storage::disk('s3');

        // Seed objects across all three sites under the tenant-scoped prefixes.
        $aObj = $media->sitePrefix($accountA->id, $siteA->id).'/generations/1/result-a.png';
        $bObj = $media->sitePrefix($accountA->id, $siteB->id).'/banners/2/banner-b.png';
        $cObj = $media->sitePrefix($accountC->id, $siteC->id).'/generations/3/result-c.png';
        $disk->put($aObj, 'A');
        $disk->put($bObj, 'B');
        $disk->put($cObj, 'C');

        // Purge site A only.
        (new PurgeSiteMediaJob($accountA->id, $siteA->id))->handle();

        $disk->assertMissing($aObj);   // the target site's media is gone
        $disk->assertExists($bObj);    // a sibling site (same account) is untouched
        $disk->assertExists($cObj);    // another account's site is untouched
    }

    public function test_deleting_a_site_dispatches_the_media_purge(): void
    {
        Bus::fake([PurgeSiteMediaJob::class]);
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, fn () => Site::query()->whereKey($site->id)->firstOrFail()->delete());

        Bus::assertDispatched(
            PurgeSiteMediaJob::class,
            fn (PurgeSiteMediaJob $job): bool => $job->accountId === $account->id && $job->siteId === $site->id,
        );
    }

    public function test_deleting_a_site_cascades_its_rows_but_keeps_the_account_ledger_and_other_sites(): void
    {
        Bus::fake([PurgeSiteMediaJob::class]); // don't actually purge; just assert the DB cascade
        $account = Account::factory()->create();
        $siteA = Site::factory()->forAccount($account)->create();
        $siteB = Site::factory()->forAccount($account)->create();

        [$bannerA, $bannerB, $ledgerId] = Tenant::run($account, function () use ($account, $siteA, $siteB) {
            $bannerA = Banner::factory()->forSite($siteA)->create();
            $bannerB = Banner::factory()->forSite($siteB)->create();
            // An account-level money record (no site_id) — must survive a site delete. Written
            // through the sanctioned ledger writer (CreditLedger has no factory by design).
            $ledger = app(CreditLedgerService::class)->grant($account, 1_000_000, IdempotencyKey::forGrant($account->id, 'purge-test'));

            return [$bannerA->id, $bannerB->id, $ledger->id];
        });

        Tenant::run($account, fn () => Site::query()->whereKey($siteA->id)->firstOrFail()->delete());

        Tenant::run($account, function () use ($bannerA, $bannerB, $ledgerId) {
            $this->assertNull(Banner::query()->find($bannerA));                 // cascaded away
            $this->assertNotNull(Banner::query()->find($bannerB));              // other site intact
            $this->assertNotNull(CreditLedger::query()->find($ledgerId));       // account ledger kept
        });
    }
}
