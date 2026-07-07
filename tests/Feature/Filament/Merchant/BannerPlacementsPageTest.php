<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Banners\BannerPlacements as PlacementSchema;
use App\Domain\Banners\BannerService;
use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Filament\Merchant\Pages\BannerPlacements;
use App\Models\Account;
use App\Models\Banner;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Banner placement picker page — the reused sandboxed-iframe multi-pick, scoped to a banner.
 * Proves: the page binds the banner (shop-scoped); a visually-clicked selector is verified
 * SERVER-SIDE (resolves-to-one) before it enters the { selector, position } list, deduped +
 * capped; removal works; save persists through the validated writer; a foreign shop's banner
 * cannot bind.
 */
class BannerPlacementsPageTest extends TestCase
{
    use RefreshDatabase;

    // A tiny store page: one unique price element + a duplicated class (to exercise "not unique").
    private const PAGE_HTML = '<html><head></head><body>'
        .'<h1 class="product__title">Tee</h1>'
        .'<span id="price" class="price">₪120</span>'
        .'<span class="dup">a</span><span class="dup">b</span>'
        .'</body></html>';

    private Account $account;

    private Site $site;

    private Banner $banner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();
        $this->banner = Tenant::run($this->account, fn () => app(BannerService::class)->createDraft($this->site, 'Promo'));

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->account)->create());
        Filament::setTenant($this->site);

        // Fake the SSRF-guarded fetcher so the live preview needs no network or browser.
        $this->app->instance(PageSource::class, new class(self::PAGE_HTML) implements PageSource {
            public function __construct(private readonly string $html) {}

            public function fetch(string $url): FetchResult
            {
                return new FetchResult(html: $this->html, finalUrl: $url, fetchedVia: 'http');
            }
        });
    }

    public function test_page_binds_the_banner_for_the_owner(): void
    {
        Tenant::run($this->account, function (): void {
            Livewire::test(BannerPlacements::class, ['banner' => $this->banner->id])
                ->assertOk()
                ->assertSet('hasBanner', true)
                ->assertSet('bannerId', $this->banner->id)
                ->assertSet('siteId', $this->site->id);
        });
    }

    public function test_pick_accumulates_only_server_verified_placements(): void
    {
        Tenant::run($this->account, function (): void {
            $component = Livewire::test(BannerPlacements::class, ['banner' => $this->banner->id])
                ->call('openPicker')
                ->set('previewUrl', 'https://shop.example/p/1')
                ->call('loadPreview')
                ->assertSet('previewError', null);

            $this->assertNotNull($component->get('previewToken'));

            // A unique element is verified + appended as { selector, position:'after' }.
            $component->call('pickPlacement', '#price')
                ->assertSet('pickVerdict.ok', true)
                ->assertSet('placements', [['selector' => '#price', 'position' => PlacementSchema::POSITION_DEFAULT]]);

            // A non-unique selector (matches 2) is flagged and NOT stored.
            $component->call('pickPlacement', '.dup')
                ->assertSet('pickVerdict.ok', false)
                ->assertSet('placements', [['selector' => '#price', 'position' => PlacementSchema::POSITION_DEFAULT]]);

            // A duplicate pick is refused.
            $component->call('pickPlacement', '#price')
                ->assertSet('pickVerdict.reason', 'duplicate')
                ->assertSet('placements', [['selector' => '#price', 'position' => PlacementSchema::POSITION_DEFAULT]]);

            // Removing drops it.
            $component->call('removePlacement', 0)
                ->assertSet('placements', []);
        });
    }

    public function test_save_persists_placements_through_the_service(): void
    {
        Tenant::run($this->account, function (): void {
            Livewire::test(BannerPlacements::class, ['banner' => $this->banner->id])
                ->set('placements', [
                    ['selector' => '.product__gallery', 'position' => 'append'],
                    ['selector' => '#promo', 'position' => 'before'],
                ])
                ->call('save')
                ->assertHasNoErrors();
        });

        $stored = Tenant::run($this->account, fn () => Banner::query()->find($this->banner->id)->placements);
        $this->assertSame([
            ['selector' => '.product__gallery', 'position' => 'append'],
            ['selector' => '#promo', 'position' => 'before'],
        ], $stored);
    }

    public function test_a_foreign_shops_banner_does_not_bind(): void
    {
        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();
        $otherBanner = Tenant::run($otherAccount, fn () => app(BannerService::class)->createDraft($otherSite, 'Theirs'));

        // Bound to OUR shop, opening another ACCOUNT's banner id must not bind it.
        Tenant::run($this->account, function () use ($otherBanner): void {
            Livewire::test(BannerPlacements::class, ['banner' => $otherBanner->id])
                ->assertOk()
                ->assertSet('hasBanner', false);
        });
    }

    public function test_a_same_account_other_shops_banner_does_not_bind(): void
    {
        // A SECOND shop under the SAME account: the page is bound to $this->site, so a banner that
        // belongs to a sibling site must NOT bind — proving the explicit where('site_id') guard
        // (the account global scope alone would not exclude it).
        $otherSite = Site::factory()->forAccount($this->account)->create();
        $siblingBanner = Tenant::run($this->account, fn () => app(BannerService::class)->createDraft($otherSite, 'Sibling'));

        Tenant::run($this->account, function () use ($siblingBanner): void {
            Livewire::test(BannerPlacements::class, ['banner' => $siblingBanner->id])
                ->assertOk()
                ->assertSet('hasBanner', false);
        });
    }
}
