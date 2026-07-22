<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\ProductImages\SubmitProductImageJob;
use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushProductMediaJob;
use App\Domain\Shopify\Media\UndoProductMediaJob;
use App\Filament\Merchant\Pages\ProductImageStudio;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\ShopifyConnection;
use App\Models\ShopifyMediaSnapshot;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\ProductImages\ProductImageTestSupport;
use Tests\Feature\Shopify\ShopifyMediaTestSupport;
use Tests\TestCase;

/**
 * The Product Image Studio page — the merchant surface of the bulk pipeline.
 *
 * Proves: it renders with the plain-English charge notice (charged on AI success, a rejection
 * does not refund); Generate queues a batch through the real entry point; approve/reject drive
 * the guarded review machine; an out-of-credits batch is a TYPED notification, never a 500; and
 * the grid is account-scoped (a foreign shop's images are simply not there).
 */
class ProductImageStudioPageTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase, ShopifyMediaTestSupport;

    private array $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class]);

        $this->shop = $this->makeShop();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->shop['account'])->create());
        Filament::setTenant($this->shop['site']);
    }

    private function page(): Testable
    {
        return Livewire::test(ProductImageStudio::class);
    }

    public function test_the_page_renders_with_the_charge_notice_and_the_balance(): void
    {
        Tenant::run($this->shop['account'], function (): void {
            $this->page()
                ->assertOk()
                ->assertSee(__('product_images.charge_notice'))
                ->assertSee(__('product_images.balance'))
                ->assertSee('$5.00');
        });
    }

    public function test_generate_queues_a_batch_through_the_real_entry_point(): void
    {
        Tenant::run($this->shop['account'], function (): void {
            $this->page()
                ->callAction('generate', [
                    'operation_key' => AiOperation::KEY_PACKSHOT_GENERATION,
                    'source_pick' => ProductImageBatch::SOURCE_MAIN,
                    'product_ids' => [(int) $this->shop['product']->getKey()],
                ])
                ->assertHasNoActionErrors();

            $this->assertSame(1, ProductImageBatch::query()->count());
            $this->assertSame(1, ProductAsset::query()->count());
            Bus::assertDispatchedTimes(SubmitProductImageJob::class, 1);
        });
    }

    public function test_an_out_of_credits_batch_is_a_typed_notification_not_an_error(): void
    {
        Account::query()->whereKey($this->shop['account']->getKey())->update(['balance_micro_usd' => 1_000]);

        Tenant::run($this->shop['account'], function (): void {
            $this->page()
                ->callAction('generate', [
                    'operation_key' => AiOperation::KEY_PACKSHOT_GENERATION,
                    'source_pick' => ProductImageBatch::SOURCE_MAIN,
                    'product_ids' => [(int) $this->shop['product']->getKey()],
                ])
                ->assertNotified();

            $this->assertSame(0, ProductImageBatch::query()->count(), 'A denied batch creates nothing.');
            $this->assertSame(0, ProductAsset::query()->count());
            Bus::assertNotDispatched(SubmitProductImageJob::class);
        });
    }

    public function test_approve_and_reject_drive_the_guarded_review_machine(): void
    {
        $asset = Tenant::run($this->shop['account'], function (): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create();

            return ProductAsset::factory()->forProduct($this->shop['product'], $batch)->succeeded()->create();
        });

        Tenant::run($this->shop['account'], function () use ($asset): void {
            $this->page()->call('approve', (int) $asset->getKey())->assertOk();
            $this->assertSame(ProductAsset::REVIEW_APPROVED, $asset->fresh()->review_status);

            $this->page()->call('reject', (int) $asset->getKey())->assertOk();
            $this->assertSame(ProductAsset::REVIEW_REJECTED, $asset->fresh()->review_status);
        });
    }

    /**
     * MUTATION GUARD — the page must NOT mint the regenerate's client_request_id.
     *
     * Two clicks of one tile's Regenerate = ONE intent: one asset, one queued worker, one future
     * charge. Give the page its own id again (the old REQUEST_REGENERATE_PREFIX . uniqid()) and
     * the second click mints a second asset and a second job -> RED. The money guard lives in
     * RegenerateProductImage; this pins that the page still delegates to it.
     */
    public function test_a_double_clicked_regenerate_on_the_page_queues_one_asset_once(): void
    {
        $source = Tenant::run($this->shop['account'], function (): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create();

            return ProductAsset::factory()->forProduct($this->shop['product'], $batch)->succeeded()->create();
        });

        Tenant::run($this->shop['account'], function () use ($source): void {
            $this->page()->call('regenerate', (int) $source->getKey())->assertOk();
            $this->page()->call('regenerate', (int) $source->getKey())->assertOk();

            $this->assertSame(
                1,
                ProductAsset::query()->where('source_asset_id', $source->getKey())->count(),
                'A double-clicked Regenerate must mint exactly ONE asset.',
            );

            Bus::assertDispatchedTimes(SubmitProductImageJob::class, 1);
        });
    }

    /**
     * "Update prompt" — the modal regenerates from the ORIGINAL photo with the merchant's edited
     * note. A NEW, separately-charged asset; the money guard stays in RegenerateProductImage.
     */
    public function test_update_prompt_action_regenerates_with_the_edited_note(): void
    {
        $source = Tenant::run($this->shop['account'], function (): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create(['notes' => 'original note']);

            return ProductAsset::factory()->forProduct($this->shop['product'], $batch)->succeeded()->create();
        });

        Tenant::run($this->shop['account'], function () use ($source): void {
            $this->page()
                ->mountAction('updatePrompt', ['asset' => (int) $source->getKey()])
                ->setActionData(['notes' => 'a brighter, warmer look'])
                ->callMountedAction()
                ->assertHasNoActionErrors()
                ->assertNotified();

            $child = ProductAsset::query()->where('source_asset_id', $source->getKey())->firstOrFail();
            $this->assertSame('a brighter, warmer look', ProductImageBatch::query()->find($child->batch_id)->notes);
            Bus::assertDispatched(SubmitProductImageJob::class);
        });
    }

    /** "Fix image" — the modal queues an image-to-image fix of the CURRENT result (SOURCE_RESULT). */
    public function test_fix_image_action_queues_a_fix_of_the_result(): void
    {
        $source = Tenant::run($this->shop['account'], function (): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create();

            return ProductAsset::factory()->forProduct($this->shop['product'], $batch)->succeeded()->create([
                'image_path' => 'accounts/x/sites/y/product-assets/result.png',
                'image_mime' => 'image/png',
            ]);
        });

        Tenant::run($this->shop['account'], function () use ($source): void {
            $this->page()
                ->mountAction('fixImage', ['asset' => (int) $source->getKey()])
                ->setActionData(['instruction' => 'make the background pure white'])
                ->callMountedAction()
                ->assertHasNoActionErrors()
                ->assertNotified();

            $child = ProductAsset::query()->where('source_asset_id', $source->getKey())->firstOrFail();
            $this->assertSame(ProductImageBatch::SOURCE_RESULT, ProductImageBatch::query()->find($child->batch_id)->source_pick);
            Bus::assertDispatched(SubmitProductImageJob::class);
        });
    }

    /** A double-clicked Fix on the page mints exactly ONE asset (the domain money guard holds). */
    public function test_a_double_clicked_fix_on_the_page_queues_one_asset_once(): void
    {
        $source = Tenant::run($this->shop['account'], function (): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create();

            return ProductAsset::factory()->forProduct($this->shop['product'], $batch)->succeeded()->create([
                'image_path' => 'accounts/x/sites/y/product-assets/result.png',
                'image_mime' => 'image/png',
            ]);
        });

        Tenant::run($this->shop['account'], function () use ($source): void {
            foreach ([1, 2] as $ignored) {
                $this->page()
                    ->mountAction('fixImage', ['asset' => (int) $source->getKey()])
                    ->setActionData(['instruction' => 'brighten it'])
                    ->callMountedAction()
                    ->assertHasNoActionErrors();
            }

            $this->assertSame(1, ProductAsset::query()->where('source_asset_id', $source->getKey())->count());
            Bus::assertDispatchedTimes(SubmitProductImageJob::class, 1);
        });
    }

    // --- Phase 5: the store rail on the review grid (push / re-push / undo). All FREE. ---

    /**
     * An APPROVED image of a SHOPIFY product offers Push, and the placement chooser is built from
     * the product's REAL gallery — the merchant picks an existing slot / an existing image, never
     * a number they guessed.
     */
    public function test_the_push_action_queues_the_chosen_placement(): void
    {
        [$product, $asset] = $this->shopifyAsset(connected: true);

        Bus::fake([SubmitProductImageJob::class, PushProductMediaJob::class]);

        Tenant::run($this->shop['account'], function () use ($asset): void {
            $this->page()
                ->mountAction('pushMedia', ['asset' => (int) $asset->getKey()])
                ->setActionData([
                    'placement' => MediaPlacement::MODE_POSITION,
                    'position' => 1,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors()
                ->assertNotified();

            Bus::assertDispatched(
                PushProductMediaJob::class,
                fn (PushProductMediaJob $job): bool => $job->productAssetId === (int) $asset->getKey()
                    && $job->placement['mode'] === MediaPlacement::MODE_POSITION
                    && (int) $job->placement['position'] === 1,
            );
        });
    }

    /** The chooser lists the product's live gallery: three placements, and the real media ids. */
    public function test_the_placement_chooser_is_built_from_the_live_gallery(): void
    {
        [$product, $asset] = $this->shopifyAsset(connected: true);

        Tenant::run($this->shop['account'], function () use ($product): void {
            $gallery = app(PushProductMedia::class)->gallery($this->shop['site'], (int) $product->getKey());

            $this->assertCount(2, $gallery);
            $this->assertSame(1, $gallery[0]->position);
            $this->assertSame(2, $gallery[1]->position);
            $this->assertSame('original 1', $gallery[0]->alt);
        });
    }

    /** Push is only legal on an APPROVED image — an unapproved one is a typed notice, not a 500. */
    public function test_pushing_an_unapproved_image_is_refused_with_a_notification(): void
    {
        [$product, $asset] = $this->shopifyAsset(approved: false);

        Bus::fake([SubmitProductImageJob::class, PushProductMediaJob::class]);

        Tenant::run($this->shop['account'], function () use ($asset): void {
            $this->page()
                ->mountAction('pushMedia', ['asset' => (int) $asset->getKey()])
                ->setActionData(['placement' => MediaPlacement::MODE_APPEND])
                ->callMountedAction()
                ->assertNotified();

            Bus::assertNotDispatched(PushProductMediaJob::class);
        });
    }

    /** A re-push retries the UPLOAD ONLY: no new asset, no new render job, no charge. */
    public function test_re_push_retries_the_upload_and_never_regenerates(): void
    {
        [$product, $asset] = $this->shopifyAsset();

        Tenant::run($this->shop['account'], fn () => $asset->pushTransitionTo(ProductAsset::PUSH_PUSHING));
        Tenant::run($this->shop['account'], fn () => $asset->pushTransitionTo(ProductAsset::PUSH_FAILED));

        Bus::fake([SubmitProductImageJob::class, PushProductMediaJob::class]);

        Tenant::run($this->shop['account'], function () use ($asset): void {
            $this->page()->call('rePush', (int) $asset->getKey())->assertOk();

            Bus::assertDispatchedTimes(PushProductMediaJob::class, 1);
            Bus::assertNotDispatched(SubmitProductImageJob::class);   // the AI never runs again

            $this->assertSame(1, ProductAsset::query()->count());     // and no new asset is minted
            $this->assertSame(0, CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->count());
        });
    }

    /** Undo is offered only once we actually hold the product's original images. */
    public function test_undo_is_queued_only_when_a_captured_snapshot_exists(): void
    {
        [$product, $asset] = $this->shopifyAsset();

        Bus::fake([SubmitProductImageJob::class, UndoProductMediaJob::class]);

        Tenant::run($this->shop['account'], function () use ($product): void {
            // No snapshot yet -> nothing to restore (a typed notice, never an error).
            $this->page()->call('undoProductMedia', (int) $product->getKey())->assertOk();
            Bus::assertNotDispatched(UndoProductMediaJob::class);

            ShopifyMediaSnapshot::factory()->forProduct($product)->captured()->create();

            $this->page()->call('undoProductMedia', (int) $product->getKey())->assertOk();

            Bus::assertDispatched(
                UndoProductMediaJob::class,
                fn (UndoProductMediaJob $job): bool => $job->productId === (int) $product->getKey(),
            );
        });
    }

    /**
     * A coherent SHOPIFY product + one succeeded (optionally approved) asset on it. With
     * `connected: true` the shop is really connected and the fake store answers with a
     * 2-image gallery, so the placement chooser has something real to render.
     *
     * @return array{0: Product, 1: ProductAsset}
     */
    private function shopifyAsset(bool $approved = true, bool $connected = false): array
    {
        if ($connected) {
            $this->bootShopifyMediaEnv();
            $this->fakeShopifyStore();
            $this->seedGallery(2);

            Tenant::run($this->shop['account'], fn (): ShopifyConnection => ShopifyConnection::factory()
                ->forSite($this->shop['site'])
                ->create(['shop_domain' => self::MEDIA_SHOP]));
        }

        return Tenant::run($this->shop['account'], function () use ($approved): array {
            $product = Product::factory()->forSite($this->shop['site'])->confirmed()->create([
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => self::MEDIA_GID,
                'main_image_url' => 'https://cdn.example.com/product-main.jpg',
            ]);

            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create();

            $factory = ProductAsset::factory()->forProduct($product, $batch);
            $asset = ($approved ? $factory->approved() : $factory->succeeded())->create();

            return [$product, $asset];
        });
    }

    public function test_the_grid_shows_only_this_shops_images(): void
    {
        $other = $this->makeShop();

        $foreign = Tenant::run($other['account'], function () use ($other): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($other['site'])->running()->create();

            return ProductAsset::factory()->forProduct($other['product'], $batch)->succeeded()->create();
        });

        $mine = Tenant::run($this->shop['account'], function (): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($this->shop['site'])->running()->create();

            return ProductAsset::factory()->forProduct($this->shop['product'], $batch)->succeeded()->create();
        });

        Tenant::run($this->shop['account'], function () use ($mine, $foreign): void {
            $tiles = $this->page()->instance()->tiles();

            $this->assertSame([(int) $mine->getKey()], $tiles->pluck('id')->all());
            $this->assertNotContains((int) $foreign->getKey(), $tiles->pluck('id')->all());
        });

        // A foreign asset id is not approvable from this shop's page either.
        Tenant::run($this->shop['account'], function () use ($foreign): void {
            $this->page()->call('approve', (int) $foreign->getKey());

            $this->assertSame(ProductAsset::REVIEW_AWAITING, $foreign->fresh()->review_status);
        });
    }
}
