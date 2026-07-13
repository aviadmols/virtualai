<?php

namespace App\Domain\ProductImages;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\OperationConfig;
use App\Domain\Credits\CreditDenied;
use App\Domain\Credits\CreditGate;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Generation\CreditEstimator;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\AiOperation;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * StartProductImageBatch — the entry point behind the Product Image Studio's "Generate".
 *
 * It plans, gates, and QUEUES; it never calls a model and never charges. The money path
 * belongs to the worker, per asset (gate -> reserve -> submit -> poll -> charge on success).
 *
 * Three guarantees it is responsible for:
 *
 *  1. ADVISORY PRE-FLIGHT. plan() prices the batch from the DB-managed operation (estimate ×
 *     markup, never a literal) and compares it to spendable credit. handle() refuses a batch
 *     the account plainly cannot pay for — a TYPED denial, not an exception — so the merchant
 *     sees "you need $X, you have $Y" instead of N assets that each cancel on the worker.
 *
 *  2. DETERMINISTIC IDENTITY. Every asset's key is a hash of {account, site, product, source
 *     photo, operation, prompt version, model, model params} + a client_request_id segment that
 *     a normal batch keeps CONSTANT. So a double-clicked Generate — or an identical second
 *     batch — finds the existing asset and SKIPS it: it cannot regenerate or re-charge an image
 *     that already exists. "Regenerate" is the explicit opposite — but its client_request_id is
 *     still DERIVED, never random (RegenerateProductImage): it varies per merchant INTENT, so a
 *     double-clicked Regenerate collapses to one asset while a deliberate later one mints a new,
 *     separately-charged asset.
 *
 *  3. COHERENT COUNTERS. The batch row carries `total` up front, so its progress bar is honest
 *     from the first paint; skipped products are recorded immediately.
 *
 * Runs inside the caller's bound tenant; account_id is stamped by BelongsToAccount.
 */
final class StartProductImageBatch
{
    // === CONSTANTS ===
    private const UNKNOWN_OPERATION_MESSAGE = 'Unknown product-image operation "%s".';

    private const UNKNOWN_SOURCE_MESSAGE = 'Unknown product-image source pick "%s".';

    /** @var array<string,OperationConfig> resolved-config memo, keyed by product type */
    private array $configs = [];

    /** The operation the current plan/queue call is running (set by assertOperation). */
    private string $operationKey = '';

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly CreditEstimator $estimator,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * The ADVISORY plan: which products can run, what it will cost, and whether the account can
     * afford it. Read-only — it creates nothing and charges nothing.
     *
     * @param  list<int>  $productIds
     */
    public function plan(Site $site, array $productIds, string $operationKey, string $sourcePick): BatchPlan
    {
        $this->assertOperation($operationKey);
        $this->assertSourcePick($sourcePick);

        $eligible = [];
        $skipped = [];

        foreach ($this->products($site, $productIds) as $product) {
            if (SourceImagePicker::urlFor($product, $sourcePick) === null) {
                $skipped[] = (int) $product->getKey();

                continue;
            }

            $eligible[] = (int) $product->getKey();
        }

        // The estimate comes from the DB-managed operation (estimated cost × the resolved
        // markup) — never a literal at this call site. It does not vary by product type
        // (only the prompt does), so one resolve prices the whole batch.
        $estimate = $this->estimator->estimateMicroUsd($this->config($site, null));

        return new BatchPlan(
            eligibleProductIds: $eligible,
            skippedProductIds: $skipped,
            estimatePerAssetMicroUsd: $estimate,
            spendableMicroUsd: $site->account->spendableMicroUsd(),
        );
    }

    /**
     * Create the batch + one pending asset per eligible product and queue the workers.
     *
     * @param  list<int>  $productIds
     * @param  string|null  $clientRequestId  null = a normal batch (fully deduped). An explicit
     *                                        "Regenerate" passes the DETERMINISTIC intent id that
     *                                        RegenerateProductImage derives — never a random one.
     * @param  int|null  $sourceAssetId  the asset a regenerate descends from (null for a batch).
     */
    public function handle(
        Site $site,
        array $productIds,
        string $operationKey,
        string $sourcePick,
        ?string $clientRequestId = null,
        ?int $sourceAssetId = null,
    ): BatchResult {
        $plan = $this->plan($site, $productIds, $operationKey, $sourcePick);
        $account = $site->account;

        if ($plan->count() === 0) {
            return BatchResult::denied(BatchResult::DENIED_NOTHING_TO_DO, $plan);
        }

        // The ADVISORY gate. The per-asset CreditGate on the worker stays authoritative
        // (defense in depth) — this one only stops an obviously unpayable batch, typed.
        $decision = CreditGate::for($account)->assertCanSpend($plan->totalMicroUsd());

        if (! $decision->passed) {
            $this->activity->record(
                kind: ActivityEvent::KIND_CREDIT_GATE_BLOCKED,
                subject: $account,
                details: [
                    'gate' => 'credit',
                    'surface' => 'product_image_batch',
                    'reason' => $decision->reason,
                    'estimate_micro_usd' => $plan->totalMicroUsd(),
                    'spendable_micro_usd' => $decision->spendableMicroUsd,
                ],
                siteId: (int) $site->getKey(),
                actor: ActivityEvent::ACTOR_MERCHANT,
            );

            return BatchResult::denied(
                $decision->reason === CreditDenied::REASON_ACCOUNT_INACTIVE
                    ? BatchResult::DENIED_ACCOUNT_INACTIVE
                    : BatchResult::DENIED_INSUFFICIENT_CREDITS,
                $plan,
            );
        }

        return $this->queue(
            $site,
            $account,
            $plan,
            $operationKey,
            $sourcePick,
            $clientRequestId ?? ProductAsset::REQUEST_BATCH,
            $sourceAssetId,
        );
    }

    /** Open the batch, create the assets, dispatch the submits. */
    private function queue(
        Site $site,
        Account $account,
        BatchPlan $plan,
        string $operationKey,
        string $sourcePick,
        string $clientRequestId,
        ?int $sourceAssetId = null,
    ): BatchResult {
        $total = $plan->count() + count($plan->skippedProductIds);

        $batch = new ProductImageBatch([
            'site_id' => $site->getKey(),
            'operation_key' => $operationKey,
            'source_pick' => $sourcePick,
            'total' => $total,
            'estimate_per_asset_micro_usd' => $plan->estimatePerAssetMicroUsd,
            'estimate_micro_usd' => $plan->totalMicroUsd(),
            'correlation_id' => (string) Str::ulid(),
        ]);
        $batch->save();

        $this->activity->record(
            kind: ActivityEvent::KIND_PRODUCT_IMAGE_BATCH_STARTED,
            subject: $batch,
            details: [
                'operation_key' => $operationKey,
                'source_pick' => $sourcePick,
                'total' => $total,
                'estimate_micro_usd' => $plan->totalMicroUsd(),
                'correlation_id' => $batch->correlation_id,
            ],
            siteId: (int) $site->getKey(),
            actor: ActivityEvent::ACTOR_MERCHANT,
        );

        // RUNNING before any dispatch: a synchronous upstream (or a sync queue driver) can
        // settle an asset the instant it is dispatched, and a settled batch must never be
        // moved back to running (the guarded machine would — correctly — throw).
        $batch->transitionTo(ProductImageBatch::STATUS_RUNNING);

        foreach ($plan->skippedProductIds as $productId) {
            $batch->recordOutcome(ProductImageBatch::COUNTER_SKIPPED);
        }

        $queued = 0;
        $skippedExisting = 0;

        foreach ($this->products($site, $plan->eligibleProductIds) as $product) {
            $asset = $this->createAsset($site, $batch, $product, $operationKey, $sourcePick, $clientRequestId, $sourceAssetId);

            if ($asset === null) {
                $skippedExisting++;
                $batch->recordOutcome(ProductImageBatch::COUNTER_SKIPPED);

                continue;
            }

            $queued++;

            SubmitProductImageJob::dispatch(
                (int) $account->getKey(),
                (int) $site->getKey(),
                (int) $asset->getKey(),
            );
        }

        return BatchResult::started($batch->refresh(), $queued, count($plan->skippedProductIds), $skippedExisting);
    }

    /**
     * The pending asset for ONE product — or null when this exact image ALREADY exists (the
     * deterministic key collides), which is the double-click / re-run wall: no second asset,
     * no second job, no second charge.
     *
     * TWO layers, both required. The exists() pre-check is the normal, quiet path; the UNIQUE
     * index on idempotency_key is the wall underneath it, and a TRULY concurrent pair of clicks
     * can pass exists() together — so the constraint violation is caught here and answered the
     * same way (this image already exists), never as a 500 on the merchant's money path.
     */
    private function createAsset(
        Site $site,
        ProductImageBatch $batch,
        Product $product,
        string $operationKey,
        string $sourcePick,
        string $clientRequestId,
        ?int $sourceAssetId = null,
    ): ?ProductAsset {
        $sourceUrl = SourceImagePicker::urlFor($product, $sourcePick);

        if ($sourceUrl === null) {
            return null; // defensive: plan() already filtered these out
        }

        $config = $this->config($site, $product->product_type ?: null);
        $sourceHash = SourceImagePicker::hash($sourceUrl);

        $key = IdempotencyKey::forProductAsset(
            accountId: (int) $site->account_id,
            siteId: (int) $site->getKey(),
            productId: (int) $product->getKey(),
            sourceImageHash: $sourceHash,
            operationKey: $operationKey,
            promptVersion: $config->promptVersion,
            modelId: $config->model,
            modelParams: $config->params,
            clientRequestId: $clientRequestId,
        );

        try {
            return DB::transaction(function () use ($site, $batch, $product, $operationKey, $sourceUrl, $sourceHash, $key, $clientRequestId, $sourceAssetId): ?ProductAsset {
                if (ProductAsset::query()->where('idempotency_key', $key)->exists()) {
                    return null;
                }

                $asset = new ProductAsset([
                    'site_id' => $site->getKey(),
                    'product_id' => $product->getKey(),
                    'batch_id' => $batch->getKey(),
                    'source_asset_id' => $sourceAssetId,
                    'operation_key' => $operationKey,
                    'status' => ProductAsset::STATUS_PENDING,
                    'review_status' => ProductAsset::REVIEW_AWAITING,
                    'client_request_id' => $clientRequestId,
                    'idempotency_key' => $key,
                    'source_image_url' => $sourceUrl,
                    'source_image_hash' => $sourceHash,
                ]);
                $asset->save();

                return $asset;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // a truly concurrent twin won the insert — same answer: it already exists
        }
    }

    /**
     * The site's ACTIVE products among the requested ids, read through the BelongsToAccount
     * global scope (a foreign product simply is not there — fail closed).
     *
     * A product does not need to be CONFIRMED: the studio transforms the merchant's own
     * catalogue photos, it does not serve the widget. Archived products are excluded.
     *
     * @param  list<int>  $productIds
     * @return Collection<int,Product>
     */
    private function products(Site $site, array $productIds)
    {
        if ($productIds === []) {
            return collect();
        }

        return Product::query()
            ->where('site_id', $site->getKey())
            ->whereKey($productIds)
            ->active()
            ->orderBy('id')
            ->get();
    }

    /** The resolved AI bag for a product type (memoized — one resolve per distinct type). */
    private function config(Site $site, ?string $productType): OperationConfig
    {
        $memoKey = $productType ?? '';

        return $this->configs[$memoKey] ??= $this->resolver->for(
            $this->operationKey,
            $site,
            $productType,
        );
    }

    private function assertOperation(string $operationKey): void
    {
        if (! in_array($operationKey, AiOperation::PRODUCT_IMAGE_KEYS, true)) {
            throw new RuntimeException(sprintf(self::UNKNOWN_OPERATION_MESSAGE, $operationKey));
        }

        if ($this->operationKey !== $operationKey) {
            $this->configs = []; // a different operation resolves a different bag
            $this->operationKey = $operationKey;
        }
    }

    private function assertSourcePick(string $sourcePick): void
    {
        if (! in_array($sourcePick, ProductImageBatch::SOURCE_PICKS, true)) {
            throw new RuntimeException(sprintf(self::UNKNOWN_SOURCE_MESSAGE, $sourcePick));
        }
    }
}
