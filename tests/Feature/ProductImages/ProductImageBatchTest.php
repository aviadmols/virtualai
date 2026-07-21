<?php

namespace Tests\Feature\ProductImages;

use App\Domain\Credits\IdempotencyKey;
use App\Domain\ProductImages\BatchResult;
use App\Domain\ProductImages\StartProductImageBatch;
use App\Domain\ProductImages\SubmitProductImageJob;
use App\Models\Account;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\StylePreset;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The batch entry point: the advisory pre-flight, the deterministic asset identity, and the
 * DB-managed AI config behind it.
 *
 * The load-bearing property: a double-clicked "Generate" — or an identical second batch — can
 * neither regenerate nor RE-CHARGE an image that already exists, because the key is a hash of
 * everything that decides the image (product, source photo, operation, prompt version, model,
 * params). "Regenerate" is the one deliberate exception: it varies the client_request_id.
 */
class ProductImageBatchTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class]);
    }

    private function start(array $shop, ?string $clientRequestId = null, string $sourcePick = ProductImageBatch::SOURCE_MAIN): BatchResult
    {
        return Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: $sourcePick,
            clientRequestId: $clientRequestId,
        ));
    }

    /**
     * MUTATION GUARD — the key-collision skip in StartProductImageBatch::createAsset().
     * Delete it and the second click inserts a duplicate key -> the unique index throws and this
     * test goes RED (a 500 on the merchant's money path instead of a silent, correct no-op).
     */
    public function test_a_double_clicked_batch_creates_one_asset_and_dispatches_one_job(): void
    {
        $shop = $this->makeShop();

        $first = $this->start($shop);
        $second = $this->start($shop);

        $this->assertSame(1, $first->queued);
        $this->assertSame(0, $second->queued, 'The same image must never be generated twice.');
        $this->assertSame(1, $second->skippedExisting);

        $assets = Tenant::run($shop['account'], fn () => ProductAsset::query()->get());
        $this->assertCount(1, $assets, 'One deterministic key = one asset, no matter how many clicks.');

        Bus::assertDispatchedTimes(SubmitProductImageJob::class, 1);

        // The second batch still exists as a row, and settled itself as fully skipped.
        $batch = Tenant::run($shop['account'], fn () => ProductImageBatch::query()->findOrFail($second->batch->getKey()));
        $this->assertSame(ProductImageBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->skipped);
    }

    /** A chosen style rides onto the asset (with its base operation) for the worker to apply. */
    public function test_a_chosen_style_is_stored_on_the_asset_with_its_base_operation(): void
    {
        $shop = $this->makeShop();

        $preset = StylePreset::create([
            'name' => 'Vintage on a model',
            'operation_key' => AiOperation::KEY_ON_MODEL_GENERATION,
            'user_prompt' => 'A vintage editorial look.',
            'status' => StylePreset::STATUS_APPROVED,
            'is_active' => true,
        ]);

        Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: (string) $preset->operation_key, // the studio derives this from the style
            sourcePick: ProductImageBatch::SOURCE_MAIN,
            styleId: $preset->id,
        ));

        $asset = Tenant::run($shop['account'], fn (): ProductAsset => ProductAsset::query()->firstOrFail());
        $this->assertSame($preset->id, (int) $asset->style_preset_id);
        $this->assertSame(AiOperation::KEY_ON_MODEL_GENERATION, $asset->operation_key);
    }

    /** "Regenerate" VARIES the key on purpose: a new asset, separately charged. */
    public function test_regenerate_varies_the_key_and_mints_a_new_asset(): void
    {
        $shop = $this->makeShop();

        $this->start($shop);
        $regen = $this->start($shop, ProductAsset::REQUEST_REGENERATE_PREFIX.'abc');

        $this->assertSame(1, $regen->queued);

        $assets = Tenant::run($shop['account'], fn () => ProductAsset::query()->orderBy('id')->get());
        $this->assertCount(2, $assets);
        $this->assertNotSame($assets[0]->idempotency_key, $assets[1]->idempotency_key);
        $this->assertSame(ProductAsset::REQUEST_BATCH, $assets[0]->client_request_id);
        $this->assertStringStartsWith(ProductAsset::REQUEST_REGENERATE_PREFIX, $assets[1]->client_request_id);
    }

    /** A DIFFERENT source photo is a different image — so a different key, and a new asset. */
    public function test_a_different_source_photo_is_a_different_asset(): void
    {
        $shop = $this->makeShop();

        $this->start($shop, sourcePick: ProductImageBatch::SOURCE_MAIN);
        $alt = $this->start($shop, sourcePick: ProductImageBatch::SOURCE_ALT_1);

        $this->assertSame(1, $alt->queued);

        $assets = Tenant::run($shop['account'], fn () => ProductAsset::query()->orderBy('id')->get());
        $this->assertCount(2, $assets);
        $this->assertNotSame($assets[0]->source_image_hash, $assets[1]->source_image_hash);
    }

    /** The key is exactly the documented hash of the inputs — not a random id. */
    public function test_the_asset_key_is_the_deterministic_hash_of_the_generation_inputs(): void
    {
        $shop = $this->makeShop();
        $this->start($shop);

        $asset = Tenant::run($shop['account'], fn () => ProductAsset::query()->firstOrFail());

        $expected = IdempotencyKey::forProductAsset(
            accountId: (int) $shop['account']->getKey(),
            siteId: (int) $shop['site']->getKey(),
            productId: (int) $shop['product']->getKey(),
            sourceImageHash: sha1('https://cdn.example.com/product-main.jpg'),
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            promptVersion: 1,
            modelId: 'fal-ai/nano-banana/edit',
            modelParams: ['temperature' => 0.2, 'top_p' => 0.9],
            clientRequestId: ProductAsset::REQUEST_BATCH,
            // A plain batch carries no style / note / ratio / quality — but the choices ARE part
            // of the fingerprint, so a batch that picked any of them mints a different image.
            extra: ['style_id' => null, 'notes' => '', 'aspect_ratio' => '', 'image_quality' => ''],
        );

        $this->assertSame($expected, $asset->idempotency_key);
        $this->assertStringStartsWith('product_asset:'.$shop['account']->getKey().':', $asset->idempotency_key);
    }

    /**
     * A per-generation CHOICE (note / ratio / quality) is part of the image identity: it varies
     * the key (so the same product with a different look is NOT skipped as a duplicate) and is
     * kept on the batch for the worker to apply.
     */
    public function test_a_generation_choice_varies_the_key_and_is_kept_on_the_batch(): void
    {
        $shop = $this->makeShop();

        $this->start($shop); // a plain batch first

        Tenant::run($shop['account'], function () use ($shop): void {
            $result = app(StartProductImageBatch::class)->handle(
                site: $shop['site'],
                productIds: [(int) $shop['product']->getKey()],
                operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
                sourcePick: ProductImageBatch::SOURCE_MAIN,
                notes: 'warm beige background #f5f5f0',
                aspectRatio: '4:5',
                imageQuality: 'high',
            );

            // The choice made it a genuinely different image — never skipped as an existing one.
            $this->assertSame(1, $result->queued);

            $batch = ProductImageBatch::query()->latest('id')->firstOrFail();
            $this->assertSame('warm beige background #f5f5f0', $batch->notes);
            $this->assertSame('4:5', $batch->aspect_ratio);
            $this->assertSame('high', $batch->image_quality);

            $keys = ProductAsset::query()->orderBy('id')->pluck('idempotency_key')->all();
            $this->assertCount(2, $keys);
            $this->assertNotSame($keys[0], $keys[1], 'A different choice must vary the idempotency key.');
        });
    }

    /** An unknown aspect ratio / quality is dropped to null — a selector can never feed a provider a value it rejects. */
    public function test_an_unknown_aspect_or_quality_is_dropped(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop['account'], function () use ($shop): void {
            app(StartProductImageBatch::class)->handle(
                site: $shop['site'],
                productIds: [(int) $shop['product']->getKey()],
                operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
                sourcePick: ProductImageBatch::SOURCE_MAIN,
                aspectRatio: '999:1',
                imageQuality: 'ultra',
            );

            $batch = ProductImageBatch::query()->latest('id')->firstOrFail();
            $this->assertNull($batch->aspect_ratio);
            $this->assertNull($batch->image_quality);
        });
    }

    /** A product with nothing in the chosen slot is SKIPPED — never generated from another photo. */
    public function test_a_product_without_the_chosen_photo_is_skipped(): void
    {
        $shop = $this->makeShop(['images' => []]);

        $result = $this->start($shop, sourcePick: ProductImageBatch::SOURCE_ALT_2);

        $this->assertTrue($result->wasDenied());
        $this->assertSame(BatchResult::DENIED_NOTHING_TO_DO, $result->deniedReason);
        $this->assertSame(0, Tenant::run($shop['account'], fn () => ProductAsset::query()->count()));
        Bus::assertNotDispatched(SubmitProductImageJob::class);
    }

    /** The ADVISORY pre-flight prices the batch from the DB operation — never a literal. */
    public function test_the_plan_prices_the_batch_from_the_db_managed_operation(): void
    {
        $shop = $this->makeShop();

        $plan = Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->plan(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: ProductImageBatch::SOURCE_MAIN,
        ));

        $this->assertSame(1, $plan->count());
        $this->assertSame(250_000, $plan->estimatePerAssetMicroUsd); // estimate × markup, floored
        $this->assertSame(250_000, $plan->totalMicroUsd());
        $this->assertSame(5_000_000, $plan->spendableMicroUsd);      // the $5 opening grant
        $this->assertTrue($plan->affordable());
    }

    /** An unaffordable batch is a TYPED denial: no batch row, no assets, no jobs — never a 500. */
    public function test_an_unaffordable_batch_is_denied_before_anything_is_created(): void
    {
        $shop = $this->makeShop();
        Account::query()->whereKey($shop['account']->getKey())->update(['balance_micro_usd' => 1_000]);

        $result = $this->start($shop);

        $this->assertTrue($result->wasDenied());
        $this->assertSame(BatchResult::DENIED_INSUFFICIENT_CREDITS, $result->deniedReason);
        $this->assertNull($result->batch);
        $this->assertSame(0, $result->plan->affordableCount());
        $this->assertSame(0, Tenant::run($shop['account'], fn () => ProductImageBatch::query()->count()));
        $this->assertSame(0, Tenant::run($shop['account'], fn () => ProductAsset::query()->count()));
        Bus::assertNotDispatched(SubmitProductImageJob::class);
    }

    /** Both operations exist in the DB control plane, on fal, with a flat-rate price. xAI is excluded. */
    public function test_both_product_image_operations_are_seeded_and_admin_tunable(): void
    {
        foreach (AiOperation::PRODUCT_IMAGE_KEYS as $key) {
            $operation = AiOperation::query()->where('operation_key', $key)->firstOrFail();

            $this->assertSame('fal-ai/nano-banana/edit', $operation->default_model);
            $this->assertNotNull($operation->fallback_model);
            $this->assertSame(40_000, $operation->estimated_cost_micro_usd);
            $this->assertNull($operation->credit_multiplier, 'null = the platform default markup, admin-settable.');

            $default = AiModel::query()->where('operation_key', $key)->where('is_default', true)->firstOrFail();
            $this->assertSame(AiModel::PROVIDER_FAL, $default->provider);
            $this->assertSame(39_000, $default->cost_hint_micro_usd);

            $this->assertSame(
                0,
                AiModel::query()->where('operation_key', $key)->where('provider', AiModel::PROVIDER_XAI)->count(),
                'xAI is text-to-image only and cannot edit a product photo.',
            );
        }

        // The two operations are INDEPENDENT (their own prompts + aspect ratios).
        $packshot = AiOperation::query()->where('operation_key', AiOperation::KEY_PACKSHOT_GENERATION)->firstOrFail();
        $onModel = AiOperation::query()->where('operation_key', AiOperation::KEY_ON_MODEL_GENERATION)->firstOrFail();
        $this->assertNotSame($packshot->aspect_ratio, $onModel->aspect_ratio);
    }

    /** An ARCHIVED product is never generated for (its photos left the catalogue). */
    public function test_an_archived_product_is_not_eligible(): void
    {
        $shop = $this->makeShop();
        Tenant::run($shop['account'], fn () => Product::query()->whereKey($shop['product']->getKey())->update(['is_active' => false]));

        $result = $this->start($shop);

        $this->assertTrue($result->wasDenied());
        $this->assertSame(BatchResult::DENIED_NOTHING_TO_DO, $result->deniedReason);
    }
}
