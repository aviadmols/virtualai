<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Media\MediaStorage;
use App\Filament\Merchant\Pages\TryOnHistory;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WS2 — the per-shop Try-on history page. Renders every try-on for the CURRENT shop
 * (the Filament tenant), newest first; a purged/failed row shows a placeholder, never
 * a broken image. Tenant-safe: the page reads only the bound shop's generations
 * (BelongsToAccount) — never another account's — and holds only scalar state.
 */
class TryOnHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');

        $this->account = Account::factory()->create();
        $this->owner = User::factory()->forAccount($this->account)->create();
        $this->site = Site::factory()->forAccount($this->account)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($this->owner);
        // The merchant panel is shop-centric (Filament tenant = Site); bind the active shop.
        Filament::setTenant($this->site);
    }

    /** Run the body with the owner's account bound, as BindMerchantAccount would. */
    private function asMerchant(callable $body): mixed
    {
        return Tenant::run($this->account->id, $body);
    }

    /** Seed one succeeded try-on (with a stored result) for the bound shop. */
    private function seedSucceeded(string $productName = 'Red Sneaker'): void
    {
        $product = Product::factory()->forSite($this->site)->confirmed()->create(['name' => $productName]);
        $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['color' => 'Red']]);
        $lead = EndUser::factory()->forSite($this->site)->registered()->create(['full_name' => 'Dana Levi']);

        $gen = Generation::factory()->forContext($lead, $product, $variant, 'crq-h1')
            ->create(['status' => Generation::STATUS_SUCCEEDED]);
        $stored = app(MediaStorage::class)->storeResult(
            (int) $this->account->id, (int) $this->site->id, (int) $gen->id, 'RESULT-h1', 'image/png',
        );
        $gen->forceFill(['result_image_path' => $stored->path])->save();
    }

    public function test_history_page_renders_try_ons_for_the_shop(): void
    {
        $this->asMerchant(function (): void {
            $this->seedSucceeded();

            Livewire::test(TryOnHistory::class)
                ->assertOk()
                ->assertSee('Red Sneaker')
                ->assertSee('Dana Levi');
        });
    }

    public function test_history_page_empty_state_when_no_try_ons(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(TryOnHistory::class)
                ->assertOk()
                ->assertSee(__('history.empty'));
        });
    }

    public function test_history_page_binds_the_current_shop(): void
    {
        $this->asMerchant(function (): void {
            $page = Livewire::test(TryOnHistory::class);

            $this->assertSame($this->site->id, $page->instance()->siteId);
            $this->assertTrue($page->instance()->hasSite);
        });
    }

    public function test_history_page_shows_a_failed_try_on_without_a_broken_image(): void
    {
        $this->asMerchant(function (): void {
            $product = Product::factory()->forSite($this->site)->confirmed()->create(['name' => 'Blue Coat']);
            $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['size' => 'L']]);
            $lead = EndUser::factory()->forSite($this->site)->create();

            // A failed generation never produced a result image.
            Generation::factory()->forContext($lead, $product, $variant, 'crq-fail')
                ->create(['status' => Generation::STATUS_FAILED, 'failure_code' => 'ai_call_failed']);

            Livewire::test(TryOnHistory::class)
                ->assertOk()
                ->assertSee('Blue Coat')
                // The failed row renders with the generation status badge, not an <img>.
                ->assertSee(__(\App\Support\Ui\StatusBadge::label('generation', Generation::STATUS_FAILED)));
        });
    }

    public function test_history_page_does_not_render_a_foreign_shops_try_ons(): void
    {
        // A second account with its own shop + a succeeded try-on.
        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();

        Tenant::run($otherAccount->id, function () use ($otherAccount, $otherSite): void {
            $product = Product::factory()->forSite($otherSite)->confirmed()->create(['name' => 'Foreign Hat']);
            $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['color' => 'Green']]);
            $lead = EndUser::factory()->forSite($otherSite)->create();
            Generation::factory()->forContext($lead, $product, $variant, 'crq-foreign')
                ->create(['status' => Generation::STATUS_SUCCEEDED]);
        });

        // Bound to account A's shop, the page never surfaces account B's product.
        $this->asMerchant(function (): void {
            $this->seedSucceeded();

            Livewire::test(TryOnHistory::class)
                ->assertOk()
                ->assertSee('Red Sneaker')
                ->assertDontSee('Foreign Hat');
        });
    }

    public function test_load_more_extends_the_window(): void
    {
        $this->asMerchant(function (): void {
            $page = Livewire::test(TryOnHistory::class);

            $this->assertSame(1, $page->instance()->loadedPages);

            $page->call('loadMore');

            $this->assertSame(2, $page->instance()->loadedPages);
        });
    }
}
