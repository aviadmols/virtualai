<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Scan\ScanConstants;
use App\Filament\Merchant\Pages\ReviewProduct;
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
 * WS4 (2) + (3) — the scan-review visual role picker. Proves the "pick on page"
 * button opens the SAME sandboxed-iframe preview the placement picker uses (from
 * the product's stored snapshot), that a picked SELECTOR role fills its manual
 * input only after a server-side resolves-to-one verify, and that a picked
 * DIMENSION role (size/weight) reads the value + round-trips into physical_dimensions
 * on confirm. The picked selector is never executed — only verified as a DOM query.
 */
class ScanReviewRolePickerTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT_HTML = <<<'HTML'
    <html><head></head><body>
      <h1 class="product__title" id="prod-title">Merino Sweater</h1>
      <button id="add-to-cart">Add to cart</button>
      <span class="product__size">Large</span>
      <span class="product__weight">420g</span>
      <span class="dup">a</span><span class="dup">b</span>
    </body></html>
    HTML;

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

        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    /** A draft product whose page snapshot is stored (so the picker previews it). */
    private function snapshottedDraft(): Product
    {
        $url = 'https://shop.example/p/42';

        $product = Product::factory()->forSite($this->site)->create([
            'status' => Product::STATUS_DRAFT,
            'source_url' => $url,
            'source_url_hash' => sha1($url),
            'field_confidence' => [
                'name' => ['value' => 'Merino Sweater', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD],
            ],
            'detected_selectors' => [
                ScanConstants::ROLE_ADD_TO_CART => ['primary' => '.old-add', 'matched_count' => 1, 'confidence' => 0.5],
            ],
        ]);

        app(PreviewSnapshotStore::class)->put($product, self::SNAPSHOT_HTML);

        return $product;
    }

    public function test_pick_button_opens_the_snapshot_preview_in_role_mode(): void
    {
        Tenant::run($this->account->id, function (): void {
            $product = $this->snapshottedDraft();

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id])
                ->call('openRolePicker', ScanConstants::ROLE_PRICE)
                ->assertSet('pickerOpen', true)
                ->assertSet('pickerRole', ScanConstants::ROLE_PRICE)
                ->assertSet('pickerIsDimension', false)
                ->assertSet('previewError', null);

            $this->assertNotNull($page->get('previewToken'));
            $this->assertSame('role', $page->instance()->pickerMode());
            // The sandboxed srcdoc carries the sanitized snapshot (styles kept, scripts stripped).
            $this->assertStringContainsString('product__title', $page->instance()->previewSrcdoc());
        });
    }

    public function test_picking_a_selector_role_fills_its_manual_input_after_server_verify(): void
    {
        Tenant::run($this->account->id, function (): void {
            $product = $this->snapshottedDraft();

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id])
                ->call('openRolePicker', ScanConstants::ROLE_ADD_TO_CART);

            // A unique element -> verdict ok + the manual input is filled.
            $page->call('pickRole', ScanConstants::ROLE_ADD_TO_CART, '#add-to-cart')
                ->assertSet('pickVerdict.ok', true)
                ->assertSet('selectors.'.ScanConstants::ROLE_ADD_TO_CART, '#add-to-cart');

            // A selector matching many elements -> NOT ok, and the input is NOT overwritten.
            $page->call('pickRole', ScanConstants::ROLE_ADD_TO_CART, '.dup')
                ->assertSet('pickVerdict.ok', false)
                ->assertSet('selectors.'.ScanConstants::ROLE_ADD_TO_CART, '#add-to-cart');
        });
    }

    public function test_picking_a_selector_role_then_confirming_persists_the_verified_selector(): void
    {
        Tenant::run($this->account->id, function (): void {
            $product = $this->snapshottedDraft();

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id])
                ->call('openRolePicker', ScanConstants::ROLE_ADD_TO_CART)
                ->call('pickRole', ScanConstants::ROLE_ADD_TO_CART, '#add-to-cart');

            // Acknowledge every remaining blocking row, then confirm.
            foreach ($page->instance()->gate()->blockingKeys as $key) {
                $page->call('markReviewed', $key);
            }

            $page->call('confirm');

            $confirmed = $product->fresh();
            $this->assertTrue($confirmed->isConfirmed());
            $this->assertSame('#add-to-cart', $confirmed->detected_selectors[ScanConstants::ROLE_ADD_TO_CART]['primary']);
            $this->assertTrue($confirmed->detected_selectors[ScanConstants::ROLE_ADD_TO_CART]['confirmed']);
        });
    }

    public function test_picking_a_dimension_role_reads_the_value_and_round_trips_into_physical_dimensions(): void
    {
        Tenant::run($this->account->id, function (): void {
            $product = $this->snapshottedDraft();

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id])
                ->call('openRolePicker', ScanConstants::ROLE_SIZE)
                ->assertSet('pickerIsDimension', true)
                ->call('pickRole', ScanConstants::ROLE_SIZE, '.product__size')
                ->assertSet('pickVerdict.ok', true)
                ->assertSet('pickVerdict.value', 'Large')
                ->assertSet('dimensionPicks.'.ScanConstants::ROLE_SIZE.'.value', 'Large');

            // Confirm (name is high; no blocking rows besides the not-detected selectors/fields).
            foreach ($page->instance()->gate()->blockingKeys as $key) {
                $page->call('markReviewed', $key);
            }
            $page->call('confirm');

            $picks = $product->fresh()->physical_dimensions[ScanConstants::DIMENSION_PICKS_KEY];
            $this->assertSame('.product__size', $picks[ScanConstants::ROLE_SIZE][ScanConstants::DIMENSION_PICK_SELECTOR]);
            $this->assertSame('Large', $picks[ScanConstants::ROLE_SIZE][ScanConstants::DIMENSION_PICK_VALUE]);

            // A dimension pick never entered the runtime selector bag.
            $this->assertArrayNotHasKey(ScanConstants::ROLE_SIZE, $product->fresh()->detected_selectors);
        });
    }

    public function test_a_missing_snapshot_opens_the_picker_with_a_soft_message(): void
    {
        Tenant::run($this->account->id, function (): void {
            $url = 'https://shop.example/p/nosnap';
            $product = Product::factory()->forSite($this->site)->create([
                'status' => Product::STATUS_DRAFT,
                'source_url' => $url,
                'source_url_hash' => sha1($url),
            ]);

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id])
                ->call('openRolePicker', ScanConstants::ROLE_PRICE)
                ->assertSet('pickerOpen', true)
                ->assertSet('previewToken', null);

            // No snapshot -> a soft, merchant-facing message, never a 500.
            $this->assertSame(__('scan.pick.errors.no_snapshot'), $page->get('previewError'));
        });
    }
}
