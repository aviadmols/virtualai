<?php

namespace Tests\Feature\ProductImages;

use App\Domain\ProductImages\BatchResult;
use App\Domain\ProductImages\PollProductImageJob;
use App\Domain\ProductImages\RegenerateProductImage;
use App\Domain\ProductImages\StartProductImageBatch;
use App\Domain\ProductImages\SubmitProductImageJob;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * THE REGENERATE RAIL — the one place the deterministic asset key is MEANT to vary, and
 * therefore the one place a double-click could pay twice.
 *
 * The law these tests pin: a regenerate's client_request_id is derived from the merchant's
 * INTENT (regen-{source}-{settled}), never minted per CLICK. Mutate RegenerateProductImage
 * ::intentId() back to a random value (uniqid/Str::random/time) and
 * test_a_double_clicked_regenerate_creates_one_asset_one_render_and_one_charge goes RED on the
 * asset count, the provider submit count AND the charge count — three independent reds, because
 * a random segment means two assets, two renders and two charge rows.
 */
class ProductImageRegenerateTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    // $5 opening grant; one fal nano-banana image charges round(0.039 × 2.5 × 1e6).
    private const FIVE_DOLLARS_MICRO = 5_000_000;

    private const EXPECTED_CHARGE_MICRO = 97_500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class, PollProductImageJob::class]);
    }

    /** A succeeded, reviewable asset produced by the real pipeline (batch -> submit -> poll). */
    private function succeededAsset(array $shop): ProductAsset
    {
        $result = Tenant::run($shop['account'], fn (): BatchResult => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: ProductImageBatch::SOURCE_MAIN,
        ));

        $this->assertSame(1, $result->queued);

        $asset = Tenant::run($shop['account'], fn (): ProductAsset => ProductAsset::query()->latest('id')->firstOrFail());

        $this->drive($shop['account'], $shop['site'], $asset);

        $this->assertSame(ProductAsset::STATUS_SUCCEEDED, $asset->fresh()->status);

        return $asset->fresh();
    }

    /** Run the worker pair by hand (Bus is faked: the poller would otherwise cascade). */
    private function drive(Account $account, Site $site, ProductAsset $asset): void
    {
        (new SubmitProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
        (new PollProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    private function regenerate(array $shop, ProductAsset $source): BatchResult
    {
        return Tenant::run($shop['account'], fn (): BatchResult => app(RegenerateProductImage::class)
            ->handle($shop['site'], (int) $source->getKey()));
    }

    private function assetCount(Account $account): int
    {
        return Tenant::run($account, fn (): int => ProductAsset::query()->count());
    }

    private function chargeCount(Account $account): int
    {
        return Tenant::run($account, fn (): int => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)
            ->count());
    }

    /**
     * THE BLOCKER (double-charge). Two Regenerate clicks on the SAME image are ONE intent:
     * one asset, one provider render, one charge row.
     *
     * MUTATION: make RegenerateProductImage::intentId() random again (the old
     * REQUEST_REGENERATE_PREFIX . uniqid('', true)) and this goes RED three times over —
     * 2 assets, 2 submits, 2 charges.
     */
    public function test_a_double_clicked_regenerate_creates_one_asset_one_render_and_one_charge(): void
    {
        $shop = $this->makeShop();
        $account = $shop['account'];
        $source = $this->succeededAsset($shop);

        $assetsBefore = $this->assetCount($account);
        $chargesBefore = $this->chargeCount($account);
        $submitsBefore = $this->falSubmitCount();
        $balanceBefore = (int) $account->fresh()->balance_micro_usd;

        // THE DOUBLE CLICK: the same button, twice, before anything has settled.
        $first = $this->regenerate($shop, $source);
        $second = $this->regenerate($shop, $source);

        $this->assertSame(1, $first->queued);
        $this->assertSame(0, $second->queued, 'The second click must queue NOTHING.');
        $this->assertSame(1, $second->skippedExisting, 'It collides on the deterministic key.');

        $this->assertSame(
            $assetsBefore + 1,
            $this->assetCount($account),
            'Two clicks of one Regenerate must mint exactly ONE asset.',
        );

        // The child: linked to its source, carrying the DERIVED (not random) intent id.
        $child = Tenant::run($account, fn (): ProductAsset => ProductAsset::query()->latest('id')->firstOrFail());
        $this->assertSame((int) $source->getKey(), (int) $child->source_asset_id);
        $this->assertSame(
            ProductAsset::REQUEST_REGENERATE_PREFIX.$source->getKey().'-0',
            $child->client_request_id,
            'The intent id is derived from the source + its settled regenerations — never random.',
        );

        // Exactly ONE new worker was queued for the pair of clicks.
        Bus::assertDispatchedTimes(SubmitProductImageJob::class, 2); // 1 batch + 1 regenerate

        // Drive the one queued render: ONE provider submit, ONE new charge, ONE debit.
        $this->drive($account, $shop['site'], $child);

        $this->assertSame(ProductAsset::STATUS_SUCCEEDED, $child->fresh()->status);
        $this->assertSame(
            $submitsBefore + 1,
            $this->falSubmitCount(),
            'Two clicks must never render (and pay for) the image twice upstream.',
        );
        $this->assertSame(
            $chargesBefore + 1,
            $this->chargeCount($account),
            'Two clicks must write exactly ONE charge row.',
        );
        $this->assertSame(
            $balanceBefore - self::EXPECTED_CHARGE_MICRO,
            (int) $account->fresh()->balance_micro_usd,
            'The merchant is debited once, for one image.',
        );
        $this->assertSame(0, (int) $account->fresh()->reserved_micro_usd);
    }

    /**
     * The other half of the law: a DELIBERATE second regenerate — asked for after the first one
     * settled — is a NEW intent, so it mints a new, separately-charged asset. (Collapsing that
     * would break the feature; the key must vary per intent, and only per intent.)
     */
    public function test_a_deliberate_second_regenerate_after_the_first_settles_mints_a_new_asset(): void
    {
        $shop = $this->makeShop();
        $account = $shop['account'];
        $source = $this->succeededAsset($shop);

        $first = $this->regenerate($shop, $source);
        $this->assertSame(1, $first->queued);

        $child = Tenant::run($account, fn (): ProductAsset => ProductAsset::query()->latest('id')->firstOrFail());
        $this->drive($account, $shop['site'], $child); // it SETTLES: the intent advances

        $second = $this->regenerate($shop, $source);

        $this->assertSame(1, $second->queued, 'A new intent must be free to run.');

        $grandchild = Tenant::run($account, fn (): ProductAsset => ProductAsset::query()->latest('id')->firstOrFail());
        $this->assertSame(
            ProductAsset::REQUEST_REGENERATE_PREFIX.$source->getKey().'-1',
            $grandchild->client_request_id,
        );
        $this->assertNotSame($child->idempotency_key, $grandchild->idempotency_key);
        $this->assertSame(3, $this->assetCount($account)); // batch + 2 deliberate regenerations
    }

    /**
     * A regenerate asked for while that image is STILL RENDERING is refused, typed — the render
     * the merchant is waiting for is the one already in flight. Nothing queued, nothing charged.
     */
    public function test_a_regenerate_of_an_in_flight_render_is_a_typed_denial(): void
    {
        $shop = $this->makeShop();
        $account = $shop['account'];
        $source = $this->succeededAsset($shop);

        $this->regenerate($shop, $source);

        $child = Tenant::run($account, fn (): ProductAsset => ProductAsset::query()->latest('id')->firstOrFail());
        (new SubmitProductImageJob((int) $account->getKey(), (int) $shop['site']->getKey(), (int) $child->getKey()))->handle();
        $this->assertSame(ProductAsset::STATUS_PROCESSING, $child->fresh()->status);

        $assets = $this->assetCount($account);
        $result = $this->regenerate($shop, $child); // the merchant clicks Regenerate on the render in flight

        $this->assertTrue($result->wasDenied());
        $this->assertSame(BatchResult::DENIED_STILL_RENDERING, $result->deniedReason);
        $this->assertSame($assets, $this->assetCount($account), 'A denial creates nothing.');
        $this->assertSame(1, $this->chargeCount($account), 'And charges nothing.');
    }

    /** A foreign shop's asset id regenerates NOTHING (fail closed — it simply is not there). */
    public function test_a_foreign_asset_cannot_be_regenerated(): void
    {
        $shop = $this->makeShop();
        $other = $this->makeShop();

        $foreign = $this->succeededAsset($other);
        $assets = $this->assetCount($shop['account']);

        $result = $this->regenerate($shop, $foreign);

        $this->assertTrue($result->wasDenied());
        $this->assertSame(BatchResult::DENIED_NOTHING_TO_DO, $result->deniedReason);
        $this->assertSame($assets, $this->assetCount($shop['account']));
        $this->assertSame(self::FIVE_DOLLARS_MICRO, (int) $shop['account']->fresh()->balance_micro_usd);
    }
}
