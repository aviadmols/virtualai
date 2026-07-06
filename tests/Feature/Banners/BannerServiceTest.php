<?php

namespace Tests\Feature\Banners;

use App\Domain\Banners\BannerContent;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Models\Account;
use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BannerService — the single validated writer of a banner's content + lifecycle. Proves it
 * creates drafts, applies only validated content, copies a chosen candidate's artwork, guards
 * activation (needs artwork) + the status machine, and rejects bad values / foreign assets with
 * a typed InvalidBannerException (nothing persisted).
 */
class BannerServiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Site $site;

    private BannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();
        $this->service = app(BannerService::class);
    }

    private function draft(): Banner
    {
        return Tenant::run($this->account, fn () => $this->service->createDraft($this->site, 'Summer Sale'));
    }

    private function succeededAsset(Banner $banner): BannerAsset
    {
        return Tenant::run($this->account, fn () => BannerAsset::factory()->forBanner($banner)->create([
            'status' => BannerAsset::STATUS_SUCCEEDED,
            'image_path' => 'accounts/1/sites/1/banners/1/banner-abc.png',
            'image_mime' => 'image/png',
            'image_width' => 1200,
            'image_height' => 675,
        ]));
    }

    public function test_create_draft_creates_a_draft_banner(): void
    {
        $banner = $this->draft();

        $this->assertSame(Banner::STATUS_DRAFT, $banner->status);
        $this->assertSame('Summer Sale', $banner->name);
        $this->assertSame((int) $this->account->id, (int) $banner->account_id);
        $this->assertSame((int) $this->site->id, (int) $banner->site_id);
    }

    public function test_create_draft_rejects_a_blank_name(): void
    {
        $this->expectException(InvalidBannerException::class);
        Tenant::run($this->account, fn () => $this->service->createDraft($this->site, '   '));
    }

    public function test_update_content_persists_validated_fields(): void
    {
        $banner = $this->draft();

        Tenant::run($this->account, fn () => $this->service->updateContent($banner, [
            BannerContent::KEY_NAME => 'Autumn Promo',
            BannerContent::KEY_COMPOSITION => Banner::COMPOSITION_OVERLAY,
            BannerContent::KEY_TARGET_URL => 'https://shop.example/sale',
            BannerContent::KEY_ALT_TEXT => 'Autumn promo banner',
            BannerContent::KEY_OVERLAY => ['headline' => 'Up to 50% off', 'cta_label' => 'Shop now', 'subtext' => '   '],
        ]));

        $fresh = Tenant::run($this->account, fn () => Banner::query()->find($banner->id));
        $this->assertSame('Autumn Promo', $fresh->name);
        $this->assertSame(Banner::COMPOSITION_OVERLAY, $fresh->composition);
        $this->assertSame('https://shop.example/sale', $fresh->target_url);
        $this->assertSame('Up to 50% off', $fresh->overlay['headline']);
        $this->assertSame('Shop now', $fresh->overlay['cta_label']);
        // A blank overlay value is dropped, not stored as "".
        $this->assertArrayNotHasKey('subtext', $fresh->overlay);
    }

    public function test_update_content_rejects_a_bad_target_url(): void
    {
        $banner = $this->draft();

        $this->expectException(InvalidBannerException::class);
        Tenant::run($this->account, fn () => $this->service->updateContent($banner, [
            BannerContent::KEY_TARGET_URL => 'javascript:alert(1)',
        ]));
    }

    public function test_update_content_rejects_an_unknown_overlay_key(): void
    {
        $banner = $this->draft();

        $this->expectException(InvalidBannerException::class);
        Tenant::run($this->account, fn () => $this->service->updateContent($banner, [
            BannerContent::KEY_OVERLAY => ['script' => '<script>'],
        ]));
    }

    public function test_select_asset_copies_the_artwork_onto_the_banner(): void
    {
        $banner = $this->draft();
        $asset = $this->succeededAsset($banner);

        Tenant::run($this->account, fn () => $this->service->selectAsset($banner, $asset));

        $fresh = Tenant::run($this->account, fn () => Banner::query()->find($banner->id));
        $this->assertSame($asset->id, $fresh->selected_asset_id);
        $this->assertSame($asset->image_path, $fresh->image_path);
        $this->assertSame('image/png', $fresh->image_mime);
        $this->assertSame(1200, $fresh->image_width);
        $this->assertTrue($fresh->hasArtwork());
    }

    public function test_select_asset_rejects_a_non_succeeded_candidate(): void
    {
        $banner = $this->draft();
        $pending = Tenant::run($this->account, fn () => BannerAsset::factory()->forBanner($banner)->create([
            'status' => BannerAsset::STATUS_PENDING,
        ]));

        $this->expectException(InvalidBannerException::class);
        Tenant::run($this->account, fn () => $this->service->selectAsset($banner, $pending));
    }

    public function test_select_asset_rejects_an_asset_from_another_banner(): void
    {
        $banner = $this->draft();
        $other = $this->draft();
        $otherAsset = $this->succeededAsset($other);

        $this->expectException(InvalidBannerException::class);
        Tenant::run($this->account, fn () => $this->service->selectAsset($banner, $otherAsset));
    }

    public function test_activate_requires_artwork(): void
    {
        $banner = $this->draft();

        try {
            Tenant::run($this->account, fn () => $this->service->setStatus($banner, Banner::STATUS_ACTIVE));
            $this->fail('expected InvalidBannerException (no artwork)');
        } catch (InvalidBannerException $e) {
            $this->assertSame(InvalidBannerException::REASON_NO_ARTWORK, $e->reason);
        }

        $this->assertSame(Banner::STATUS_DRAFT, $banner->fresh()->status);
    }

    public function test_status_machine_activate_pause_resume_with_artwork(): void
    {
        $banner = $this->draft();
        $asset = $this->succeededAsset($banner);

        Tenant::run($this->account, function () use ($banner, $asset) {
            $this->service->selectAsset($banner, $asset);
            $this->service->setStatus($banner, Banner::STATUS_ACTIVE);
        });
        $this->assertSame(Banner::STATUS_ACTIVE, $banner->fresh()->status);

        Tenant::run($this->account, fn () => $this->service->setStatus($banner, Banner::STATUS_PAUSED));
        $this->assertSame(Banner::STATUS_PAUSED, $banner->fresh()->status);

        Tenant::run($this->account, fn () => $this->service->setStatus($banner, Banner::STATUS_ACTIVE));
        $this->assertSame(Banner::STATUS_ACTIVE, $banner->fresh()->status);
    }
}
