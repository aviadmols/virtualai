<?php

namespace Tests\Feature\Banners;

use App\Domain\Banners\BannerRules;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Models\Account;
use App\Models\Banner;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BannerRules — the display-rules schema (audience / pages / schedule / frequency / locales) +
 * its writer + the active-in-schedule model logic. Proves valid input is accepted, defaults fill
 * absent keys, every bad value is rejected (typed, nothing persisted), resolve is lenient, the
 * service persists a validated set, and only active + in-window banners pass withinSchedule().
 */
class BannerRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitize_accepts_valid_rules(): void
    {
        $clean = BannerRules::sanitize([
            'audience' => BannerRules::AUDIENCE_CLUB_MEMBERS,
            'pages' => ['context' => BannerRules::PAGE_PDP, 'url_contains' => '/sale'],
            'schedule' => ['starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-08-01 00:00:00'],
            'frequency' => ['max_per_session' => 3],
            'locales' => ['he', 'he', 'en'],
        ]);

        $this->assertSame(BannerRules::AUDIENCE_CLUB_MEMBERS, $clean['audience']);
        $this->assertSame(BannerRules::PAGE_PDP, $clean['pages']['context']);
        $this->assertSame('/sale', $clean['pages']['url_contains']);
        $this->assertSame(3, $clean['frequency']['max_per_session']);
        $this->assertSame(['he', 'en'], $clean['locales']); // deduped
        $this->assertNotNull($clean['schedule']['starts_at']);
    }

    public function test_sanitize_applies_defaults_for_absent_keys(): void
    {
        $this->assertSame(BannerRules::DEFAULTS, BannerRules::sanitize([]));
    }

    public function test_sanitize_rejects_unknown_audience(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerRules::sanitize(['audience' => 'vips']);
    }

    public function test_sanitize_rejects_unknown_page_context(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerRules::sanitize(['pages' => ['context' => 'checkout']]);
    }

    public function test_sanitize_rejects_a_bad_date(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerRules::sanitize(['schedule' => ['starts_at' => 'not-a-date']]);
    }

    public function test_sanitize_rejects_end_before_start(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerRules::sanitize(['schedule' => ['starts_at' => '2026-08-01 00:00:00', 'ends_at' => '2026-07-01 00:00:00']]);
    }

    public function test_sanitize_rejects_frequency_out_of_range(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerRules::sanitize(['frequency' => ['max_per_session' => 500]]);
    }

    public function test_sanitize_rejects_an_unknown_locale(): void
    {
        $this->expectException(InvalidBannerException::class);
        BannerRules::sanitize(['locales' => ['fr']]);
    }

    public function test_resolve_completes_and_ignores_corrupt_values(): void
    {
        $resolved = BannerRules::resolve([
            'audience' => 'garbage',
            'pages' => ['context' => 'nowhere', 'url_contains' => '/keep'],
            'frequency' => ['max_per_session' => 9999],
            'locales' => ['he', 'zz'],
        ]);

        $this->assertSame(BannerRules::AUDIENCE_ANY, $resolved['audience']);      // corrupt -> default
        $this->assertSame(BannerRules::PAGE_ANY, $resolved['pages']['context']);  // corrupt -> default
        $this->assertSame('/keep', $resolved['pages']['url_contains']);           // valid kept
        $this->assertSame(0, $resolved['frequency']['max_per_session']);          // out of range -> default
        $this->assertSame(['he'], $resolved['locales']);                          // unknown dropped
    }

    public function test_service_persists_validated_rules(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $banner = Tenant::run($account, function () use ($site) {
            $service = app(BannerService::class);
            $banner = $service->createDraft($site, 'Ruled');
            $service->updateRules($banner, ['audience' => BannerRules::AUDIENCE_NON_MEMBERS, 'frequency' => ['max_per_session' => 2]]);

            return $banner;
        });

        $fresh = Tenant::run($account, fn () => Banner::query()->find($banner->id));
        $this->assertSame(BannerRules::AUDIENCE_NON_MEMBERS, $fresh->rules['audience']);
        $this->assertSame(2, $fresh->rules['frequency']['max_per_session']);
    }

    public function test_active_scope_and_within_schedule(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($site) {
            // Active, open-ended -> shown.
            $open = Banner::factory()->forSite($site)->active()->create();
            // Active but the window ended yesterday -> NOT within schedule.
            $ended = Banner::factory()->forSite($site)->active()->create([
                'rules' => ['schedule' => ['ends_at' => Carbon::now()->subDay()->toIso8601String()]],
            ]);
            // Active but starts next week -> NOT yet within schedule.
            $future = Banner::factory()->forSite($site)->active()->create([
                'rules' => ['schedule' => ['starts_at' => Carbon::now()->addWeek()->toIso8601String()]],
            ]);
            // A draft -> not active().
            $draft = Banner::factory()->forSite($site)->create();

            $activeIds = Banner::query()->active()->pluck('id')->all();
            $this->assertContains($open->id, $activeIds);
            $this->assertContains($ended->id, $activeIds);
            $this->assertNotContains($draft->id, $activeIds);

            $this->assertTrue($open->withinSchedule());
            $this->assertFalse($ended->withinSchedule());
            $this->assertFalse($future->withinSchedule());
        });
    }
}
