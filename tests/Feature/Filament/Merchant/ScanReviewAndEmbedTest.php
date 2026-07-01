<?php

namespace Tests\Feature\Filament\Merchant;

use App\Filament\Merchant\Pages\ReviewProduct;
use App\Filament\Merchant\Resources\SiteResource\Pages\ViewSite;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M3 / M4 (A4 / A5) render + gate + tenancy tests for the merchant scan-review
 * form and the site hub / embed-code page. Proves the pages render bound to the
 * ScanReview / ConfirmGate / SiteKeyRegenerator contracts, that the no-auto-
 * approve gate is enforced server-side (no UI bypass), that a regenerate rotates
 * the PUBLIC key only (widget_secret untouched), and that a foreign account's
 * product/site 404s through the global scope (no manual where(account_id)).
 */
class ScanReviewAndEmbedTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->owner = User::factory()->forAccount($this->account)->create();
        $this->site = Site::factory()->forAccount($this->account)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($this->owner);
        Filament::setTenant($this->site); // shop-centric panel: bind the active shop
    }

    /** A draft product with a deliberate mix of confidence + selectors. */
    private function draftProduct(): Product
    {
        $product = Product::factory()->forSite($this->site)->create([
            'status' => Product::STATUS_DRAFT,
            'name' => 'Demo Tee',
            'field_confidence' => [
                'name' => ['value' => 'Demo Tee', 'confidence' => 0.93],
                'price' => ['value' => '89.00', 'confidence' => 0.81],
                'description' => ['value' => 'Desc', 'confidence' => 0.55],
                'product_type' => ['value' => 'apparel', 'confidence' => 0.30],   // low → blocks
                'main_image_url' => ['value' => null, 'confidence' => null],        // none → blocks
            ],
            'detected_selectors' => [
                'add_to_cart' => ['primary' => 'button.add-to-cart', 'matched_count' => 1, 'confidence' => 0.92],
                'product_image' => ['primary' => 'img.photo', 'matched_count' => 3, 'confidence' => 0.40], // low → blocks
                'title' => ['primary' => 'h1.title', 'matched_count' => 1, 'confidence' => 0.88],
                'price' => ['primary' => '.price', 'matched_count' => 1, 'confidence' => 0.74],
                'description' => ['primary' => null, 'matched_count' => 0, 'confidence' => null],            // none → blocks
                'variations' => ['primary' => 'select#v', 'matched_count' => 1, 'confidence' => 0.66],
            ],
        ]);

        ProductVariant::factory()->forProduct($product)->create();

        return $product;
    }

    /** Run the body with the owner's account bound, as BindMerchantAccount would. */
    private function asMerchant(callable $body): mixed
    {
        return Tenant::run($this->account->id, $body);
    }

    public function test_scan_review_page_renders_bound_to_the_contract(): void
    {
        $this->asMerchant(function (): void {
            $product = $this->draftProduct();

            Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id])
                ->assertOk()
                ->assertSee(__('scan.fields_heading'))
                ->assertSee(__('scan.selectors_heading'))
                ->assertSee(__('scan.action.confirm'));
        });
    }

    public function test_confirm_is_blocked_until_every_blocking_row_is_reviewed(): void
    {
        $this->asMerchant(function (): void {
            $product = $this->draftProduct();

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id]);

            // The gate is closed on open (low + not_detected rows unreviewed).
            $this->assertFalse($page->instance()->gate()->canConfirm);

            // Confirming while blocked is a graceful no-op — product stays draft.
            $page->call('confirm');
            $this->assertSame(Product::STATUS_DRAFT, $product->fresh()->status);
        });
    }

    public function test_confirm_succeeds_once_all_blocking_rows_are_acknowledged(): void
    {
        $this->asMerchant(function (): void {
            $product = $this->draftProduct();

            $page = Livewire::test(ReviewProduct::class, ['site' => $this->site->id, 'product' => $product->id]);

            foreach ($page->instance()->gate()->blockingKeys as $key) {
                $page->call('markReviewed', $key);
            }

            $this->assertTrue($page->instance()->gate()->canConfirm);

            $page->call('confirm');
            $this->assertSame(Product::STATUS_CONFIRMED, $product->fresh()->status);
        });
    }

    public function test_a_foreign_accounts_product_is_not_reachable(): void
    {
        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();
        $foreign = Product::factory()->forSite($otherSite)->create(['status' => Product::STATUS_DRAFT]);

        // Bound to account A, the foreign site/product 404s through the global
        // scope — no manual where(account_id), no withoutGlobalScopes().
        $this->expectException(ModelNotFoundException::class);

        $this->asMerchant(fn () => Livewire::test(
            ReviewProduct::class,
            ['site' => $otherSite->id, 'product' => $foreign->id],
        ));
    }

    public function test_site_hub_renders_the_embed_block_with_the_public_key_only(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])
                ->assertOk()
                ->assertSee(__('embed.title'))
                ->assertSee($this->site->site_key)
                ->assertDontSee($this->site->getRawOriginal('widget_secret'));
        });
    }

    public function test_regenerate_rotates_the_public_key_without_touching_the_secret(): void
    {
        $this->asMerchant(function (): void {
            $originalKey = $this->site->site_key;
            $originalSecret = $this->site->widget_secret;

            Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])
                ->call('askRegenerate')
                ->call('regenerate');

            $fresh = $this->site->fresh();
            $this->assertNotSame($originalKey, $fresh->site_key);
            $this->assertSame($originalSecret, $fresh->widget_secret);
        });
    }
}
