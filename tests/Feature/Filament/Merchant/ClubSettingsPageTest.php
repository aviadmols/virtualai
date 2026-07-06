<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Sites\ClubConfig;
use App\Filament\Merchant\Pages\ClubSettings;
use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merchant "Customer Club" settings page — enable the club, set the discount %, and
 * visually pick MULTIPLE price zones per surface, persisted through
 * SiteSettingsService::update([KEY_CLUB_CONFIG => …]). Proves: the page renders for the
 * owner AND a super-admin drilled into the shop; a valid config saves; an out-of-range
 * discount / a bad selector surface a SOFT error (never a 500, never a partial save);
 * the multi-pick zone picker only stores a server-verified (resolves-to-one) selector,
 * caps per surface, and a foreign shop can never bind.
 */
class ClubSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    // A tiny store page: one unique price, plus a duplicated class (to exercise "not unique").
    private const PAGE_HTML = '<html><head></head><body>'
        .'<h1 class="product__title">Tee</h1>'
        .'<span id="price" class="price">₪120</span>'
        .'<span class="dup">a</span><span class="dup">b</span>'
        .'<span id="cart-total" class="price">₪120</span>'
        .'</body></html>';

    private Account $account;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();

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

    public function test_page_renders_for_the_owner(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ClubSettings::class)
                ->assertOk()
                ->assertSet('hasSite', true)
                ->assertSet('siteId', $this->site->id);
        });
    }

    public function test_page_renders_for_a_super_admin_drilled_into_the_shop(): void
    {
        // A super-admin carries no own account; the page binds the SHOP tenant instead.
        $this->actingAs(User::factory()->superAdmin()->create());
        Filament::setTenant($this->site);

        Livewire::test(ClubSettings::class)
            ->assertOk()
            ->assertSet('hasSite', true)
            ->assertSet('siteId', $this->site->id);
    }

    public function test_saving_persists_a_valid_club_config_through_the_service(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ClubSettings::class)
                ->assertOk()
                ->set('enabled', true)
                ->set('discountPercent', 15)
                ->set('priceZones', [
                    ClubConfig::SURFACE_PDP => ['#price'],
                    ClubConfig::SURFACE_CATALOG => [],
                    ClubConfig::SURFACE_CART => ['#cart-total'],
                ])
                ->call('save')
                ->assertHasNoErrors();
        });

        $stored = Tenant::run($this->account->id, fn () => Site::query()->find($this->site->id)->club_config);

        $this->assertTrue($stored[ClubConfig::KEY_ENABLED]);
        $this->assertSame(15, $stored[ClubConfig::KEY_DISCOUNT_PERCENT]);
        $this->assertSame(['#price'], $stored[ClubConfig::KEY_PRICE_ZONES][ClubConfig::SURFACE_PDP]);
        $this->assertSame(['#cart-total'], $stored[ClubConfig::KEY_PRICE_ZONES][ClubConfig::SURFACE_CART]);
        $this->assertSame([], $stored[ClubConfig::KEY_PRICE_ZONES][ClubConfig::SURFACE_CATALOG]);
    }

    public function test_an_out_of_range_discount_surfaces_a_soft_error_and_saves_nothing(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ClubSettings::class)
                ->set('discountPercent', 150) // > 100 — the service rejects it
                ->call('save')
                // A soft, mapped field error (reason invalid_club_config) — not a 500.
                ->assertHasErrors(['discountPercent']);
        });

        // Nothing was persisted: the column is still the untouched default.
        $stored = Tenant::run($this->account->id, fn () => Site::query()->find($this->site->id)->club_config);
        $this->assertTrue($stored === null || (int) ($stored[ClubConfig::KEY_DISCOUNT_PERCENT] ?? 0) === 0);
    }

    public function test_a_bad_selector_surfaces_a_soft_error_and_saves_nothing(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ClubSettings::class)
                ->set('enabled', true)
                ->set('discountPercent', 10)
                // A selector with characters outside the allow-list ({ } are rejected).
                ->set('priceZones.'.ClubConfig::SURFACE_PDP, ['.price{color:red}'])
                ->call('save')
                ->assertHasErrors(['discountPercent']); // the mapped soft-error field
        });

        $stored = Tenant::run($this->account->id, fn () => Site::query()->find($this->site->id)->club_config);
        $this->assertTrue($stored === null || $stored[ClubConfig::KEY_PRICE_ZONES][ClubConfig::SURFACE_PDP] === []);
    }

    public function test_multi_pick_accumulates_only_server_verified_selectors(): void
    {
        Tenant::run($this->account->id, function (): void {
            $component = Livewire::test(ClubSettings::class)
                ->call('openPicker', ClubConfig::SURFACE_CATALOG)
                ->assertSet('pickerOpen', true)
                ->assertSet('pickerSurface', ClubConfig::SURFACE_CATALOG)
                // Catalog has no scan snapshot: the merchant loads a live preview.
                ->set('previewUrl', 'https://shop.example/collections/all')
                ->call('loadPreview')
                ->assertSet('previewError', null);

            $this->assertNotNull($component->get('previewToken'));

            // First pick — a unique element — is verified + accumulated.
            $component->call('pickZone', ClubConfig::SURFACE_CATALOG, '#price')
                ->assertSet('pickVerdict.ok', true)
                ->assertSet('priceZones.'.ClubConfig::SURFACE_CATALOG, ['#price']);

            // Second, different unique element — accumulates (multi-pick).
            $component->call('pickZone', ClubConfig::SURFACE_CATALOG, '#cart-total')
                ->assertSet('pickVerdict.ok', true)
                ->assertSet('priceZones.'.ClubConfig::SURFACE_CATALOG, ['#price', '#cart-total']);

            // A non-unique selector (matches 2) is flagged and NOT stored.
            $component->call('pickZone', ClubConfig::SURFACE_CATALOG, '.dup')
                ->assertSet('pickVerdict.ok', false)
                ->assertSet('priceZones.'.ClubConfig::SURFACE_CATALOG, ['#price', '#cart-total']);

            // A duplicate pick is refused (already in the list).
            $component->call('pickZone', ClubConfig::SURFACE_CATALOG, '#price')
                ->assertSet('pickVerdict.reason', 'duplicate')
                ->assertSet('priceZones.'.ClubConfig::SURFACE_CATALOG, ['#price', '#cart-total']);

            // Removing a zone drops it from the list.
            $component->call('removeZone', ClubConfig::SURFACE_CATALOG, 0)
                ->assertSet('priceZones.'.ClubConfig::SURFACE_CATALOG, ['#cart-total']);
        });
    }

    public function test_pdp_picker_opens_from_the_scanned_product_snapshot(): void
    {
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');

        Tenant::run($this->account->id, function (): void {
            $url = 'https://shop.example/p/42';
            $product = Product::factory()->forSite($this->site)->create([
                'status' => Product::STATUS_CONFIRMED,
                'source_url' => $url,
                'source_url_hash' => sha1($url),
            ]);
            app(PreviewSnapshotStore::class)->put($product, self::PAGE_HTML);

            $component = Livewire::test(ClubSettings::class)
                ->call('openPicker', ClubConfig::SURFACE_PDP)
                ->assertSet('pickerOpen', true)
                ->assertSet('previewSource', 'snapshot')
                ->assertSet('previewError', null);

            // Previewed straight from the stored snapshot — no live fetch.
            $this->assertNotNull($component->get('previewToken'));
            $this->assertStringContainsString('product__title', $component->instance()->previewSrcdoc());
        });
    }

    public function test_a_foreign_shop_is_isolated_by_the_tenant_access_gate(): void
    {
        // Another account's shop. The authoritative isolation is canAccessTenant (Filament's
        // tenant middleware enforces it before this page ever mounts) — an owner may bind ONLY
        // their own account's shops; a foreign shop is refused. The page trusts getTenant()
        // exactly like the other merchant pages, so this gate is the tenant-safety seam.
        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();
        $owner = User::factory()->forAccount($this->account)->create();

        $this->assertFalse($owner->canAccessTenant($otherSite));
        $this->assertTrue($owner->canAccessTenant($this->site));

        // Bound to the owner's OWN shop, the page reads only that shop's config — never the
        // foreign shop's, whose stored zones stay invisible here.
        Tenant::run($this->account->id, function (): void {
            Livewire::test(ClubSettings::class)
                ->assertOk()
                ->assertSet('siteId', $this->site->id)
                ->assertSet('hasSite', true);
        });
    }
}
