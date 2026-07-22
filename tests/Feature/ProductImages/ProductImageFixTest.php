<?php

namespace Tests\Feature\ProductImages;

use App\Domain\Generation\GenerationFailureCode;
use App\Domain\Media\MediaStorage;
use App\Domain\ProductImages\BatchResult;
use App\Domain\ProductImages\FixProductImage;
use App\Domain\ProductImages\PollProductImageJob;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Fix image" — an image-to-image correction of a finished result. Proves the delicate parts:
 * the worker edits the RESULT bytes (resolved fresh at run-time, not the product photo), the
 * idempotency identity is the STABLE (id, image_path) hash (a double-click collapses to one
 * charge; a deliberate re-fix / a changed instruction varies), a non-fixable / foreign source is
 * a typed denial, and a source whose result is gone cancels BEFORE the reserve (zero charge).
 *
 * Bus::fake() is mandatory (the poller re-dispatches itself); every job is driven by hand.
 */
class ProductImageFixTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class, PollProductImageJob::class]);
    }

    /** Start a one-product batch and return [account, site, pendingAsset]. */
    private function startBatch(array $shop): array
    {
        $result = Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: ProductImageBatch::SOURCE_MAIN,
        ));

        $this->assertSame(1, $result->queued);
        $asset = Tenant::run($shop['account'], fn () => ProductAsset::query()->latest('id')->firstOrFail());

        return [$shop['account'], $shop['site'], $asset];
    }

    private function runSubmit(Account $account, Site $site, ProductAsset $asset): void
    {
        (new SubmitProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    private function runPoll(Account $account, Site $site, ProductAsset $asset): void
    {
        (new PollProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    /** A SUCCEEDED base asset (batch → submit → poll), ready to be fixed. */
    private function succeedBase(array $shop = []): array
    {
        $shop = $shop !== [] ? $shop : $this->makeShop();
        [$account, $site, $asset] = $this->startBatch($shop);

        $this->runSubmit($account, $site, $asset);
        $this->runPoll($account, $site, $asset);

        $asset->refresh();
        $this->assertTrue($asset->isSucceeded());
        $this->assertNotNull($asset->image_path);

        return [$account, $site, $asset];
    }

    private function fix(Account $account, Site $site, int $assetId, string $instruction): BatchResult
    {
        return Tenant::run($account, fn () => app(FixProductImage::class)->handle($site, $assetId, $instruction));
    }

    /** @return Collection<int,CreditLedger> */
    private function charges(Account $account)
    {
        return Tenant::run($account, fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->get());
    }

    /** The image_url values POSTed to the provider on SUBMIT (not the queue-status/result URLs). */
    private function submittedImageUrls(): array
    {
        return Http::recorded(fn ($request): bool => $request->method() === 'POST' && ! str_contains($request->url(), '/requests/'))
            ->map(fn ($pair) => $pair[0]->data()['image_url'] ?? null)
            ->all();
    }

    public function test_fix_edits_the_result_bytes_and_grounds_the_prompt_in_the_instruction(): void
    {
        [$account, $site, $base] = $this->succeedBase();

        $result = $this->fix($account, $site, (int) $base->id, 'make the background pure white');
        $this->assertSame(1, $result->queued);

        $child = Tenant::run($account, fn () => ProductAsset::query()->latest('id')->firstOrFail());
        $this->assertSame((int) $base->id, (int) $child->source_asset_id);
        $this->assertSame('fix-'.$base->id.'-0', $child->client_request_id);

        // The batch's source is the RESULT sentinel, and the idempotency identity is the STABLE
        // (id, image_path) hash — never the sha1 of an expiring signed url.
        $childBatch = Tenant::run($account, fn () => ProductImageBatch::query()->findOrFail($child->batch_id));
        $this->assertSame(ProductImageBatch::SOURCE_RESULT, $childBatch->source_pick);
        $this->assertSame(sha1('asset:'.$base->id.':'.$base->image_path), $child->source_image_hash);

        // Drive the fix render. The worker fed the model the RESULT BYTES as a data: URI (not the
        // product photo url).
        $this->runSubmit($account, $site, $child);
        $imageUrls = $this->submittedImageUrls();
        $this->assertTrue(collect($imageUrls)->contains(fn ($u): bool => is_string($u) && str_starts_with($u, 'data:image')));
        $this->assertFalse(collect($imageUrls)->contains(fn ($u): bool => is_string($u) && str_contains($u, 'product-main.jpg') && str_starts_with($u, 'data:image')));

        $this->runPoll($account, $site, $child);
        $child->refresh();
        $this->assertTrue($child->isSucceeded());
        // The correction reached the model (the prompt snapshot preserves it).
        $this->assertStringContainsString('make the background pure white', (string) ($child->meta[ProductAsset::META_PROMPT_SNAPSHOT] ?? ''));
    }

    public function test_a_double_clicked_fix_creates_one_asset_one_render_and_one_charge(): void
    {
        [$account, $site, $base] = $this->succeedBase();
        $submitsBefore = $this->falSubmitCount();

        $first = $this->fix($account, $site, (int) $base->id, 'brighten it');
        $second = $this->fix($account, $site, (int) $base->id, 'brighten it'); // same instruction, before settle

        $this->assertSame(1, $first->queued);
        $this->assertSame(0, $second->queued);
        $this->assertSame(1, $second->skippedExisting);

        $children = Tenant::run($account, fn () => ProductAsset::query()->where('source_asset_id', $base->id)->get());
        $this->assertCount(1, $children);

        $this->runSubmit($account, $site, $children->first());
        $this->runPoll($account, $site, $children->first());

        $this->assertSame($submitsBefore + 1, $this->falSubmitCount()); // one extra render, not two
        $this->assertCount(2, $this->charges($account)); // the base + exactly one fix
    }

    public function test_a_deliberate_second_fix_after_settle_mints_a_new_asset(): void
    {
        [$account, $site, $base] = $this->succeedBase();

        $first = $this->fix($account, $site, (int) $base->id, 'brighten it');
        $child = Tenant::run($account, fn () => ProductAsset::query()->latest('id')->firstOrFail());
        $this->runSubmit($account, $site, $child);
        $this->runPoll($account, $site, $child); // settle the first fix

        $second = $this->fix($account, $site, (int) $base->id, 'brighten it more');
        $this->assertSame(1, $second->queued);

        $newest = Tenant::run($account, fn () => ProductAsset::query()->latest('id')->firstOrFail());
        $this->assertSame('fix-'.$base->id.'-1', $newest->client_request_id);
        $this->assertNotSame($child->idempotency_key, $newest->idempotency_key);
    }

    public function test_a_changed_instruction_before_settle_is_not_a_duplicate(): void
    {
        [$account, $site, $base] = $this->succeedBase();

        $first = $this->fix($account, $site, (int) $base->id, 'make it warmer');
        $second = $this->fix($account, $site, (int) $base->id, 'make it cooler'); // same intent id, different note

        $this->assertSame(1, $first->queued);
        $this->assertSame(1, $second->queued); // a different instruction is a different image

        $children = Tenant::run($account, fn () => ProductAsset::query()->where('source_asset_id', $base->id)->get());
        $this->assertCount(2, $children);
        $this->assertNotSame($children[0]->idempotency_key, $children[1]->idempotency_key);
    }

    public function test_fixing_a_non_succeeded_or_foreign_or_empty_is_a_typed_denial(): void
    {
        // A pending (not-yet-succeeded) asset has nothing to fix.
        $shop = $this->makeShop();
        [$account, $site, $pending] = $this->startBatch($shop);
        $this->assertTrue($this->fix($account, $site, (int) $pending->id, 'x')->wasDenied());

        // A foreign shop's asset — fail closed (never fixable across tenants).
        [$accountB, $siteB, $baseB] = $this->succeedBase();
        $this->assertTrue($this->fix($account, $site, (int) $baseB->id, 'x')->wasDenied());

        // An empty instruction is a no-op denial.
        [$accountA, $siteA, $baseA] = $this->succeedBase();
        $this->assertTrue($this->fix($accountA, $siteA, (int) $baseA->id, '   ')->wasDenied());

        // No stray assets or charges were minted by any denial.
        $this->assertCount(0, Tenant::run($account, fn () => ProductAsset::query()->where('source_asset_id', $pending->id)->get()));
    }

    public function test_a_fix_whose_source_result_is_gone_cancels_before_the_reserve(): void
    {
        [$account, $site, $base] = $this->succeedBase();
        $chargesBefore = $this->charges($account)->count();

        // The stored result disappears (retention purge / delete) between mint and the worker run.
        Tenant::run($account, fn () => app(MediaStorage::class)->delete($base->image_path));

        $this->fix($account, $site, (int) $base->id, 'brighten it');
        $child = Tenant::run($account, fn () => ProductAsset::query()->latest('id')->firstOrFail());

        $this->runSubmit($account, $site, $child);

        $child->refresh();
        $this->assertSame(ProductAsset::STATUS_CANCELLED, $child->status);
        $this->assertSame(GenerationFailureCode::SOURCE_IMAGE_MISSING, $child->failure_code);
        $this->assertSame(0, $child->reserved_micro_usd);
        $this->assertCount($chargesBefore, $this->charges($account)); // never charged
        $this->assertSame(0, $account->fresh()->reserved_micro_usd);
    }
}
