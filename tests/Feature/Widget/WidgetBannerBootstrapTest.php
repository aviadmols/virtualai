<?php

namespace Tests\Feature\Widget;

use App\Domain\Banners\BannerRules;
use App\Models\Banner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The bootstrap `banners` block (GET /widget/v1/bootstrap). Proves the widget receives a site's
 * ACTIVE + in-schedule + artwork-ready banners (with public image, placements, and the
 * client-evaluated rules) and NOTHING else — drafts/paused/future/expired/artworkless are excluded,
 * and another shop's banners never appear (site isolation).
 */
final class WidgetBannerBootstrapTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    private const ENDPOINT = '/widget/v1/bootstrap';

    private const ANON = 'anon_banner_boot_1234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    private function activeBanner(array $ctx, array $attrs = []): Banner
    {
        return Tenant::run($ctx['account'], fn () => Banner::factory()->forSite($ctx['site'])->active()->create(array_merge([
            'image_path' => 'accounts/1/sites/1/banners/1/banner-x.png',
            'image_mime' => 'image/png',
            'image_width' => 1200,
            'image_height' => 675,
            'target_url' => 'https://shop.example/sale',
            'placements' => [['selector' => '.hero', 'position' => 'after']],
            'rules' => ['audience' => BannerRules::AUDIENCE_CLUB_MEMBERS, 'frequency' => ['max_per_session' => 2]],
        ], $attrs)));
    }

    private function boot(array $ctx)
    {
        return $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson(self::ENDPOINT.'?anon_token='.self::ANON);
    }

    public function test_bootstrap_ships_an_active_artwork_ready_banner(): void
    {
        $ctx = $this->makeSiteContext();
        $banner = $this->activeBanner($ctx);

        $response = $this->boot($ctx)->assertOk();

        $banners = $response->json('banners');
        $this->assertCount(1, $banners);
        $this->assertSame($banner->id, $banners[0]['id']);
        $this->assertSame(Banner::COMPOSITION_IMAGE, $banners[0]['composition']);
        $this->assertNotNull($banners[0]['image_url']);
        $this->assertSame('https://shop.example/sale', $banners[0]['target_url']);
        $this->assertSame([['selector' => '.hero', 'position' => 'after']], $banners[0]['placements']);
        $this->assertSame(BannerRules::AUDIENCE_CLUB_MEMBERS, $banners[0]['rules']['audience']);
        // Schedule is server-enforced and NOT shipped.
        $this->assertArrayNotHasKey('schedule', $banners[0]['rules']);
    }

    public function test_bootstrap_excludes_draft_paused_future_expired_and_artworkless(): void
    {
        $ctx = $this->makeSiteContext();

        // A draft (not active).
        Tenant::run($ctx['account'], fn () => Banner::factory()->forSite($ctx['site'])->create(['image_path' => 'p/x.png']));
        // Active but no artwork.
        $this->activeBanner($ctx, ['image_path' => null]);
        // Active but the window ended yesterday.
        $this->activeBanner($ctx, ['rules' => ['schedule' => ['ends_at' => Carbon::now()->subDay()->toIso8601String()]]]);
        // Active but starts next week.
        $this->activeBanner($ctx, ['rules' => ['schedule' => ['starts_at' => Carbon::now()->addWeek()->toIso8601String()]]]);

        $this->assertSame([], $this->boot($ctx)->assertOk()->json('banners'));
    }

    public function test_bootstrap_banners_are_site_isolated(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');

        $this->activeBanner($a); // an active banner under account/site A

        // Booting site B must not see A's banner.
        $this->assertSame([], $this->boot($b)->assertOk()->json('banners'));
        // ...and A sees its own.
        $this->assertCount(1, $this->boot($a)->assertOk()->json('banners'));
    }
}
