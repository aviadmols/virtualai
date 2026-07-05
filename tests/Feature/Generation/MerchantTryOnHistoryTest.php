<?php

namespace Tests\Feature\Generation;

use App\Domain\Generation\History\MerchantTryOnHistory;
use App\Domain\Generation\History\TryOnHistoryItem;
use App\Domain\Media\MediaStorage;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WS2 — the per-shop try-on history read. Unlike the gallery (succeeded only), the
 * history carries EVERY generation status (the mechanism's activations), newest
 * first, with the shopper name for the lead-card deep-link and a purged/placeholder
 * flag. Tenant-safe: account B can never see account A's generations.
 */
class MerchantTryOnHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    private function history(): MerchantTryOnHistory
    {
        return app(MerchantTryOnHistory::class);
    }

    /**
     * Seed one site with generations spanning statuses at increasing timestamps:
     * a succeeded one (with a stored result), a failed one, and a cancelled one.
     *
     * @return array{account: Account, site: Site, lead: EndUser}
     */
    private function seedSiteHistory(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $lead = Tenant::run($account, function () use ($account, $site) {
            $product = Product::factory()->forSite($site)->confirmed()->create(['name' => 'Red Sneaker']);
            $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['color' => 'Red', 'size' => 'M']]);

            $lead = EndUser::factory()->forSite($site)->registered()->create(['full_name' => 'Dana Levi']);

            // Oldest -> newest so we can assert order deterministically.
            $succeeded = Generation::factory()->forContext($lead, $product, $variant, 'crq-ok')
                ->create(['status' => Generation::STATUS_SUCCEEDED, 'created_at' => Carbon::parse('2026-01-01 10:00:00')]);
            $stored = app(MediaStorage::class)->storeResult(
                (int) $account->id, (int) $site->id, (int) $succeeded->id, 'RESULT-ok', 'image/png',
            );
            $succeeded->forceFill(['result_image_path' => $stored->path])->save();

            Generation::factory()->forContext($lead, $product, $variant, 'crq-fail')
                ->create(['status' => Generation::STATUS_FAILED, 'failure_code' => 'ai_call_failed', 'created_at' => Carbon::parse('2026-01-02 10:00:00')]);

            Generation::factory()->forContext($lead, $product, $variant, 'crq-cancel')
                ->create(['status' => Generation::STATUS_CANCELLED, 'created_at' => Carbon::parse('2026-01-03 10:00:00')]);

            return $lead;
        });

        return compact('account', 'site', 'lead');
    }

    public function test_history_lists_every_status_not_just_succeeded(): void
    {
        $ctx = $this->seedSiteHistory();

        $result = $this->history()->forSite($ctx['site']);
        $items = $result['items'];

        // All three (succeeded, failed, cancelled) — the gallery would show only 1.
        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $items);
        $this->assertInstanceOf(TryOnHistoryItem::class, $items->first());

        $statuses = $items->map(fn (TryOnHistoryItem $i) => $i->status)->all();
        $this->assertContains(Generation::STATUS_SUCCEEDED, $statuses);
        $this->assertContains(Generation::STATUS_FAILED, $statuses);
        $this->assertContains(Generation::STATUS_CANCELLED, $statuses);
    }

    public function test_history_is_newest_first(): void
    {
        $ctx = $this->seedSiteHistory();

        $items = $this->history()->forSite($ctx['site'])['items'];

        // The cancelled one is newest (2026-01-03), the succeeded one oldest (2026-01-01).
        $this->assertSame(Generation::STATUS_CANCELLED, $items->first()->status);
        $this->assertSame(Generation::STATUS_SUCCEEDED, $items->last()->status);
    }

    public function test_history_carries_the_shopper_name_and_id_for_the_lead_link(): void
    {
        $ctx = $this->seedSiteHistory();

        $succeeded = $this->history()->forSite($ctx['site'])['items']
            ->first(fn (TryOnHistoryItem $i) => $i->succeeded());

        $this->assertSame((int) $ctx['lead']->id, $succeeded->endUserId);
        $this->assertSame('Dana Levi', $succeeded->endUserName);
        $this->assertTrue($succeeded->hasLead());
        $this->assertSame(['color' => 'Red', 'size' => 'M'], $succeeded->variantOptions);
        $this->assertNotNull($succeeded->resultThumbnailUrl);
        $this->assertFalse($succeeded->purged);
    }

    public function test_history_flags_a_purged_result_with_a_placeholder_never_a_broken_image(): void
    {
        $ctx = $this->seedSiteHistory();

        // Simulate retention: drop the result bytes but keep the succeeded generation row.
        $gen = Tenant::run($ctx['account'], fn () => Generation::query()
            ->where('site_id', $ctx['site']->id)
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->firstOrFail());
        app(MediaStorage::class)->delete($gen->result_image_path);

        $item = $this->history()->forSite($ctx['site'])['items']
            ->first(fn (TryOnHistoryItem $i) => $i->generationId === (int) $gen->id);

        $this->assertTrue($item->purged);
        $this->assertNull($item->resultThumbnailUrl);
    }

    public function test_history_is_tenant_isolated_account_b_cannot_see_account_a(): void
    {
        $a = $this->seedSiteHistory();
        $b = $this->seedSiteHistory();

        // Querying B's history returns ONLY B's generations — never A's (BelongsToAccount
        // global scope inside the site's own tenant; a forgotten filter fails closed).
        $bItems = $this->history()->forSite($b['site'])['items'];

        $aGenerationIds = Tenant::run($a['account'], fn () => Generation::query()
            ->where('site_id', $a['site']->id)->pluck('id')->all());

        $this->assertCount(3, $bItems);
        $this->assertTrue(
            $bItems->every(fn (TryOnHistoryItem $i) => ! in_array($i->generationId, $aGenerationIds, true)),
            "an account A generation leaked into account B's history",
        );
    }

    public function test_history_pages_and_reports_has_more(): void
    {
        $ctx = $this->seedSiteHistory();

        // 3 seeded; a page size of 2 has a second page.
        $firstPage = $this->history()->forSite($ctx['site'], page: 1, perPage: 2);
        $this->assertCount(2, $firstPage['items']);
        $this->assertTrue($firstPage['hasMore']);

        $secondPage = $this->history()->forSite($ctx['site'], page: 2, perPage: 2);
        $this->assertCount(1, $secondPage['items']);
        $this->assertFalse($secondPage['hasMore']);
    }
}
