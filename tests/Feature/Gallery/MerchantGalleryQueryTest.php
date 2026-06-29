<?php

namespace Tests\Feature\Gallery;

use App\Domain\Gallery\GalleryItem;
use App\Domain\Gallery\MerchantGalleryQuery;
use App\Domain\Media\MediaStorage;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GAP-M3 — the merchant gallery read (per-site, optional end-user filter). Returns
 * immutable GalleryItem DTOs with signed thumbnails / purged flags, and is tenant-safe:
 * account B can never see account A's generations.
 */
class MerchantGalleryQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    private function gallery(): MerchantGalleryQuery
    {
        return app(MerchantGalleryQuery::class);
    }

    /**
     * Seed one site with a succeeded generation (stored result), a second succeeded
     * generation for a different lead, and a failed one (which the gallery excludes).
     *
     * @return array{account: Account, site: Site, lead: EndUser, otherLead: EndUser}
     */
    private function seedSiteGallery(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $context = Tenant::run($account, function () use ($account, $site) {
            $product = Product::factory()->forSite($site)->confirmed()->create(['name' => 'Red Sneaker']);
            $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['color' => 'Red', 'size' => 'M']]);

            $lead = EndUser::factory()->forSite($site)->create();
            $otherLead = EndUser::factory()->forSite($site)->create();

            $this->makeSucceeded($account, $site, $lead, $product, $variant, 'crq-ok-1');
            $this->makeSucceeded($account, $site, $otherLead, $product, $variant, 'crq-ok-2');

            // A failed generation is NOT part of the gallery wall.
            Generation::factory()->forContext($lead, $product, $variant, 'crq-fail')
                ->create(['status' => Generation::STATUS_FAILED, 'failure_code' => 'ai_call_failed']);

            return compact('lead', 'otherLead');
        });

        return ['account' => $account, 'site' => $site] + $context;
    }

    /** A succeeded generation with a stored result image (signed-URL path exercised). */
    private function makeSucceeded(Account $account, Site $site, EndUser $lead, Product $product, ProductVariant $variant, string $crq): Generation
    {
        $gen = Generation::factory()->forContext($lead, $product, $variant, $crq)
            ->create(['status' => Generation::STATUS_SUCCEEDED]);

        $stored = app(MediaStorage::class)->storeResult(
            (int) $account->id, (int) $site->id, (int) $gen->id, 'RESULT-'.$crq, 'image/png',
        );
        $gen->forceFill(['result_image_path' => $stored->path])->save();

        return $gen;
    }

    public function test_gallery_returns_only_succeeded_generations_with_signed_thumbnails(): void
    {
        $ctx = $this->seedSiteGallery();

        $items = $this->gallery()->forSite($ctx['site']);

        // 2 succeeded; the failed one is excluded.
        $this->assertCount(2, $items);
        $this->assertInstanceOf(GalleryItem::class, $items->first());
        $this->assertTrue($items->every(fn (GalleryItem $i) => $i->status === Generation::STATUS_SUCCEEDED));
        $this->assertTrue($items->every(fn (GalleryItem $i) => $i->resultThumbnailUrl !== null && ! $i->purged));
        $this->assertTrue($items->every(fn (GalleryItem $i) => $i->productName === 'Red Sneaker'));
        $this->assertTrue($items->every(fn (GalleryItem $i) => $i->variantOptions === ['color' => 'Red', 'size' => 'M']));
    }

    public function test_gallery_can_be_narrowed_to_one_end_user(): void
    {
        $ctx = $this->seedSiteGallery();

        $items = $this->gallery()->forSite($ctx['site'], $ctx['lead']);

        $this->assertCount(1, $items);
        $this->assertSame((int) $ctx['lead']->id, $items->first()->endUserId);
    }

    public function test_gallery_flags_a_purged_result(): void
    {
        $ctx = $this->seedSiteGallery();

        // Simulate retention: drop the result bytes but keep the generation row.
        $gen = Tenant::run($ctx['account'], fn () => Generation::query()
            ->where('site_id', $ctx['site']->id)
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->firstOrFail());
        app(MediaStorage::class)->delete($gen->result_image_path);

        $item = $this->gallery()->forSite($ctx['site'], $ctx['lead'])->first();

        $this->assertTrue($item->purged);
        $this->assertNull($item->resultThumbnailUrl);
    }

    public function test_gallery_is_tenant_isolated_account_b_cannot_see_account_a(): void
    {
        $a = $this->seedSiteGallery();
        $b = $this->seedSiteGallery();

        // Querying B's gallery returns ONLY B's generations — never A's (BelongsToAccount
        // global scope inside the site's own tenant; a forgotten filter fails closed).
        $bItems = $this->gallery()->forSite($b['site']);

        $aGenerationIds = Tenant::run($a['account'], fn () => Generation::query()
            ->where('site_id', $a['site']->id)->pluck('id')->all());

        $this->assertCount(2, $bItems);
        $this->assertTrue(
            $bItems->every(fn (GalleryItem $i) => ! in_array($i->generationId, $aGenerationIds, true)),
            "an account A generation leaked into account B's gallery",
        );
    }
}
