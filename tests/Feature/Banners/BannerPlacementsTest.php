<?php

namespace Tests\Feature\Banners;

use App\Domain\Banners\BannerPlacements;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Models\Account;
use App\Models\Banner;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BannerPlacements — the { selector, position } list schema + its writer. Proves the allow-list,
 * the position enum + default, dedupe by selector, the per-banner cap, and that the service
 * persists a validated set (a scriptable selector never reaches storage).
 */
class BannerPlacementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitize_accepts_valid_placements_and_defaults_position(): void
    {
        $clean = BannerPlacements::sanitize([
            ['selector' => '.hero', 'position' => 'before'],
            ['selector' => '#promo'], // no position -> defaults to 'after'
        ]);

        $this->assertSame(
            [
                ['selector' => '.hero', 'position' => 'before'],
                ['selector' => '#promo', 'position' => BannerPlacements::POSITION_DEFAULT],
            ],
            $clean,
        );
    }

    public function test_sanitize_dedupes_by_selector_last_position_wins(): void
    {
        $clean = BannerPlacements::sanitize([
            ['selector' => '.hero', 'position' => 'before'],
            ['selector' => '.hero', 'position' => 'append'],
        ]);

        $this->assertCount(1, $clean);
        $this->assertSame('append', $clean[0]['position']);
    }

    public function test_sanitize_rejects_a_scriptable_selector(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerPlacements::sanitize([['selector' => '.hero<script>']]);
    }

    public function test_sanitize_rejects_an_unknown_position(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerPlacements::sanitize([['selector' => '.hero', 'position' => 'sideways']]);
    }

    public function test_sanitize_rejects_too_many_placements(): void
    {
        $many = [];
        for ($i = 0; $i <= BannerPlacements::MAX; $i++) {
            $many[] = ['selector' => '.spot-'.$i];
        }

        $this->expectException(InvalidBannerException::class);
        BannerPlacements::sanitize($many);
    }

    public function test_service_persists_validated_placements(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $banner = Tenant::run($account, fn () => app(BannerService::class)->createDraft($site, 'Placed'));

        // A set containing a blank selector is rejected atomically — nothing persisted.
        try {
            Tenant::run($account, fn () => app(BannerService::class)->updatePlacements($banner, [
                ['selector' => '.product__gallery', 'position' => 'append'],
                ['selector' => '', 'position' => 'after'],
            ]));
            $this->fail('expected InvalidBannerException');
        } catch (InvalidBannerException $e) {
            $this->assertSame(InvalidBannerException::REASON_INVALID_PLACEMENTS, $e->reason);
        }

        $this->assertNull(Tenant::run($account, fn () => Banner::query()->find($banner->id))->placements);

        // A clean set persists.
        Tenant::run($account, fn () => app(BannerService::class)->updatePlacements($banner, [
            ['selector' => '.product__gallery', 'position' => 'append'],
        ]));

        $fresh = Tenant::run($account, fn () => Banner::query()->find($banner->id));
        $this->assertSame([['selector' => '.product__gallery', 'position' => 'append']], $fresh->placements);
    }
}
