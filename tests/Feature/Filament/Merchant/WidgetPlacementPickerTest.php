<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Sites\WidgetAppearance;
use App\Filament\Merchant\Pages\WidgetAppearanceSettings;
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
 * Visual placement picker (the Widget-appearance page actions): load a preview via the guarded
 * fetcher, verify a picked selector server-side, apply it into the form, and persist a custom
 * placement. The guarded PageSource is faked so no network/browser is needed.
 */
class WidgetPlacementPickerTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_HTML = '<html><head></head><body><h1 class="product__title">Tee</h1><button id="add-to-cart">Add</button></body></html>';

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

        // Fake the SSRF-guarded fetcher so the preview needs no network or headless browser.
        $this->app->instance(PageSource::class, new class(self::PAGE_HTML) implements PageSource {
            public function __construct(private readonly string $html) {}

            public function fetch(string $url): FetchResult
            {
                return new FetchResult(html: $this->html, finalUrl: $url, fetchedVia: 'http');
            }
        });
    }

    public function test_load_pick_apply_and_save_persists_a_custom_placement(): void
    {
        Tenant::run($this->account->id, function (): void {
            $component = Livewire::test(WidgetAppearanceSettings::class)
                ->assertOk()
                ->set('previewUrl', 'https://shop.example/p/1')
                ->call('loadPreview')
                ->assertSet('previewError', null);

            $this->assertNotNull($component->get('previewToken'));
            // The sandboxed srcdoc carries the sanitized store page (styles kept, scripts gone).
            $this->assertStringContainsString('product__title', $component->instance()->previewSrcdoc());

            $component
                ->call('verifyPick', '#add-to-cart', WidgetAppearance::POSITION_BEFORE)
                ->assertSet('pickVerdict.ok', true)
                ->call('applyPick')
                ->assertSet('data.'.WidgetAppearance::KEY_PLACEMENT, WidgetAppearance::PLACEMENT_CUSTOM)
                ->call('save');
        });

        $stored = Tenant::run($this->account->id, fn () => Site::query()->find($this->site->id)->widget_appearance);

        $this->assertSame(WidgetAppearance::PLACEMENT_CUSTOM, $stored[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('#add-to-cart', $stored[WidgetAppearance::KEY_CUSTOM_ANCHOR]);
        $this->assertSame(WidgetAppearance::POSITION_BEFORE, $stored[WidgetAppearance::KEY_CUSTOM_POSITION]);
    }

    public function test_open_picker_previews_the_scanned_product_from_its_snapshot(): void
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

            $component = Livewire::test(WidgetAppearanceSettings::class)
                ->call('openPicker')
                ->assertSet('pickerOpen', true)
                ->assertSet('previewSource', 'snapshot')
                ->assertSet('previewError', null);

            // Previewed straight from the stored snapshot — no live fetch was needed.
            $this->assertNotNull($component->get('previewToken'));
            $this->assertStringContainsString('product__title', $component->instance()->previewSrcdoc());
        });
    }

    public function test_verify_pick_reports_uniqueness_against_the_cached_dom(): void
    {
        Tenant::run($this->account->id, function (): void {
            $component = Livewire::test(WidgetAppearanceSettings::class)
                ->set('previewUrl', 'https://shop.example/p/1')
                ->call('loadPreview');

            $component->call('verifyPick', '#add-to-cart', WidgetAppearance::POSITION_AFTER)
                ->assertSet('pickVerdict.ok', true);

            $component->call('verifyPick', '#missing', WidgetAppearance::POSITION_AFTER)
                ->assertSet('pickVerdict.ok', false);
        });
    }

    public function test_apply_pick_is_refused_without_a_verified_selector(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(WidgetAppearanceSettings::class)
                ->call('applyPick')
                // Placement stays at the resolved default; nothing was applied.
                ->assertSet('data.'.WidgetAppearance::KEY_PLACEMENT, WidgetAppearance::PLACEMENT_AFTER_ATC);
        });
    }

    public function test_floating_corner_sets_a_fixed_placement(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(WidgetAppearanceSettings::class)
                ->call('useFloatingCorner', WidgetAppearance::PLACEMENT_FIXED_BR)
                ->assertSet('data.'.WidgetAppearance::KEY_PLACEMENT, WidgetAppearance::PLACEMENT_FIXED_BR);
        });
    }

    public function test_a_bad_url_surfaces_an_error_and_no_preview(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(WidgetAppearanceSettings::class)
                ->set('previewUrl', 'not a url')
                ->call('loadPreview')
                ->assertSet('previewToken', null)
                ->assertSet('previewError', __('appearance.visual.errors.bad_url'));
        });
    }

    public function test_a_foreign_site_deeplink_cannot_bind_another_account(): void
    {
        // Another account's site — a ?site= deep-link to it must never bind here.
        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();

        Tenant::run($this->account->id, function () use ($otherSite): void {
            $component = Livewire::withQueryParams(['site' => $otherSite->id])
                ->test(WidgetAppearanceSettings::class);

            // The foreign id is scoped out by BelongsToAccount, so the page never binds it: it
            // shows the empty state (hasSite=false) and can never load a preview for, or read the
            // cache of, another account's site.
            $this->assertFalse($component->get('hasSite'));
            $this->assertNotSame($otherSite->id, $component->get('siteId'));
        });
    }
}
