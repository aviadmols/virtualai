<?php

namespace Tests\Feature\Widget;

use App\Models\Banner;
use App\Models\BannerEvent;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /widget/v1/banners/event — per-banner impression/click analytics. Proves an event records
 * for a banner of the BOUND site (append-only, account/site stamped), a forged banner_id for
 * another shop records NOTHING (isolation), and a bad kind is a typed 422 (never a 500). Always
 * fire-and-forget typed JSON.
 */
final class WidgetBannerEventTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    private const ENDPOINT = '/widget/v1/banners/event';

    private const ANON = 'anon_banner_evt_1234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    private function bannerFor(array $ctx): Banner
    {
        return Tenant::run($ctx['account'], fn () => Banner::factory()->forSite($ctx['site'])->create());
    }

    public function test_records_an_impression_and_a_click(): void
    {
        $ctx = $this->makeSiteContext();
        $banner = $this->bannerFor($ctx);
        $headers = $this->widgetHeaders($ctx['site'], $ctx['origin']);

        $this->withHeaders($headers)->postJson(self::ENDPOINT, [
            'banner_id' => $banner->id, 'kind' => 'impression', 'anon_token' => self::ANON, 'path' => '/collections/all',
        ])->assertOk()->assertExactJson(['ok' => true, 'recorded' => true]);

        $this->withHeaders($headers)->postJson(self::ENDPOINT, [
            'banner_id' => $banner->id, 'kind' => 'click', 'anon_token' => self::ANON,
        ])->assertOk();

        $counts = Tenant::run($ctx['account'], fn () => BannerEvent::query()->where('banner_id', $banner->id)
            ->selectRaw('kind, count(*) c')->groupBy('kind')->pluck('c', 'kind')->all());

        $this->assertSame(1, (int) ($counts['impression'] ?? 0));
        $this->assertSame(1, (int) ($counts['click'] ?? 0));
    }

    public function test_an_event_for_a_foreign_shops_banner_records_nothing(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');
        $foreignBanner = $this->bannerFor($b); // belongs to shop B

        // Post to shop A's endpoint with shop B's banner id — the recorder rejects it (not A's).
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))
            ->postJson(self::ENDPOINT, ['banner_id' => $foreignBanner->id, 'kind' => 'click'])
            ->assertOk();

        $total = Tenant::run($b['account'], fn () => BannerEvent::query()->where('banner_id', $foreignBanner->id)->count());
        $this->assertSame(0, $total);
    }

    public function test_a_bad_kind_is_a_typed_422(): void
    {
        $ctx = $this->makeSiteContext();
        $banner = $this->bannerFor($ctx);

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, ['banner_id' => $banner->id, 'kind' => 'hover'])
            ->assertStatus(422);
    }
}
