<?php

namespace App\Domain\ProductImages;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\ProductImageCaller;
use App\Domain\Ai\StylePresetApplier;
use App\Domain\Credits\CreditDenied;
use App\Domain\Credits\CreditGate;
use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\Reservation;
use App\Domain\Credits\ReservationManager;
use App\Domain\Generation\CreditEstimator;
use App\Domain\Generation\GenerationFailureCode;
use App\Domain\Generation\ProductFacts;
use App\Domain\Media\MediaStorage;
use App\Jobs\TenantAwareJob;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SubmitProductImageJob — the FIRST half of one bulk product-image transform: gate, reserve,
 * and SUBMIT. It never waits for the render.
 *
 * Order (it never varies):
 *   1. row-lock the asset + ledger pre-check  -> an already-charged/terminal asset short-circuits;
 *   2. the resolved AI bag (DB-managed: model, prompt, params — never a literal here);
 *   3. a flat-rate model with no configured price is refused BEFORE anything is spent;
 *   4. CreditGate (the merchant) — a denial is TYPED (pending -> cancelled), never a 500;
 *   5. RESERVE the estimate BEFORE the provider call;
 *   6. SUBMIT. An ASYNC upstream (fal) hands back a request id: we persist it, move the asset to
 *      processing, and hand off to PollProductImageJob. A SYNC upstream (OpenRouter) produced the
 *      image inside the submit — the SAME job then finalizes it. The adapter decides; the shape
 *      is uniform.
 *
 * WHY THE RESERVE LIVES HERE and not in the batch entry point: the reservation's in-flight hold
 * has a TTL, and a bulk batch can sit in the queue far longer than that. Reserving at the moment
 * of the call — and RENEWING it on every poll tick — is what keeps the hold and the
 * accounts.reserved_micro_usd column in lock-step. (The batch's pre-flight gate is advisory.)
 *
 * ShouldBeUnique on the asset's deterministic key: a double dispatch is DROPPED before it runs.
 * And even if it ran, the row lock + the provider_request_id wall below make a second submit
 * impossible — a re-submitted render would be a second image we pay for twice.
 */
final class SubmitProductImageJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const REFERENCE_TYPE = CreditLedger::REFERENCE_PRODUCT_ASSET;

    // tries=1: a queue retry must NEVER re-run the money path. An escaped exception is caught by
    // failed(), which releases the hold and closes the asset — no charge, no stranded reservation.
    public int $tries = 1;

    public int $timeout = 70;

    // How long the poller waits before its first tick (a fal render rarely finishes sooner).
    private const FIRST_POLL_DELAY_SECONDS = 8;

    // Prompt placeholders this operation supplies (on top of ProductFacts' product data).
    private const VAR_PRODUCT_NAME = 'product_name';

    private const VAR_PRODUCT_TYPE = 'product_type';

    private const MSG_COST_NOT_CONFIGURED = 'platform.generation.cost_not_configured';

    private const MSG_SOURCE_MISSING = 'The source product image is missing or not fetchable.';

    private const MISSING_KEY_PREFIX = 'product_asset:missing:';

    // The unique lock MUST expire: a SIGKILLed worker (where failed() never runs) would otherwise
    // hold it forever and that asset could never be re-dispatched. Comfortably longer than the
    // whole submit + poll budget, so it can never release the wall while a render is still live.
    private const UNIQUE_FOR_SECONDS = 3600;

    public int $uniqueFor = self::UNIQUE_FOR_SECONDS;

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $productAssetId,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config('trayon.queues.bulk'));
    }

    /**
     * ShouldBeUnique key = the asset's stored idempotency key. Resolved BEFORE handle() binds
     * the tenant, so bind the EXPLICIT account_id and read through the normal fail-closed global
     * scope — never withoutGlobalScopes(). Only the key string crosses out.
     */
    public function uniqueId(): string
    {
        return Tenant::run($this->accountId, function (): string {
            $key = ProductAsset::query()
                ->whereKey($this->productAssetId)
                ->value('idempotency_key');

            return (string) ($key ?? self::MISSING_KEY_PREFIX.$this->productAssetId);
        });
    }

    /**
     * Last-resort safety net (Laravel calls this when handle() throws; tries=1). Bind the tenant,
     * release any still-held reservation — a leaked hold would silently eat the merchant's
     * spendable credit forever — and close the asset. An escaped exception NEVER charged.
     */
    public function failed(Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            $asset = ProductAsset::query()->find($this->productAssetId);

            if ($asset === null || $asset->isTerminal()) {
                return;
            }

            $this->reservations()->releaseByKey($this->accountId, (string) $asset->idempotency_key);

            $this->finalizer()->fail(
                $asset,
                Reservation::forKey($this->accountId, (string) $asset->idempotency_key, 0),
                GenerationFailureCode::INTERNAL_ERROR,
                $e->getMessage(),
            );
        });
    }

    /** Runs with $this->accountId bound by TenantAwareJob::handle(). */
    protected function process(): void
    {
        $asset = $this->lockAndPrecheck();

        if ($asset === null) {
            return; // already charged / already submitted / terminal — idempotent short-circuit
        }

        $site = Site::query()->findOrFail($this->siteId);
        $account = Account::query()->findOrFail($this->accountId);
        $product = Product::query()->findOrFail($asset->product_id);

        $config = $this->resolver()->for($asset->operation_key, $site, $product->product_type ?: null);

        // Apply the merchant's chosen STYLE (swaps only the user prompt; fail-open on a stale id).
        $config = app(StylePresetApplier::class)->applyTo($config, $asset->style_preset_id);

        // Apply the batch's per-generation choices: append the free-text art-direction note, and
        // override aspect ratio / image quality when the merchant set them. Each is a no-op on an
        // empty value, so an untouched choice keeps the operation's configured default. Money is
        // unchanged — none of these alters the model or its per-image price.
        $batch = $asset->batch_id !== null ? ProductImageBatch::query()->find($asset->batch_id) : null;

        if ($batch !== null) {
            $config = $config
                ->withAppendedUserPrompt($batch->notes)
                ->withAspectRatio($batch->aspect_ratio)
                ->withImageQuality($batch->image_quality);
        }

        // A flat-rate model with NO configured price could never charge honestly -> refuse BEFORE
        // reserving or rendering (no wasted spend upstream, no dishonest charge here).
        if ($config->flatRatePriceMissing()) {
            $this->finalizer()->cancel(
                $asset,
                GenerationFailureCode::AI_COST_NOT_CONFIGURED,
                (string) __(self::MSG_COST_NOT_CONFIGURED, ['model' => $config->model]),
                ['model' => $config->model],
            );

            return;
        }

        // The INPUT image: a product photo (a stored, stable url) OR — for a "fix" — the CURRENT
        // result's bytes, resolved FRESH here at run-time (never a signed url stored at mint time,
        // which could have expired). Resolved BEFORE the reserve, so any miss is a clean pre-reserve
        // cancel: no hold, no charge.
        $source = $batch !== null && $batch->source_pick === ProductImageBatch::SOURCE_RESULT
            ? $this->resolveResultSource($asset)
            : $this->resolvePhotoSource($asset);

        if ($source === null) {
            $this->finalizer()->cancel($asset, GenerationFailureCode::SOURCE_IMAGE_MISSING, self::MSG_SOURCE_MISSING);

            return;
        }

        $estimate = $this->estimator()->estimateMicroUsd($config);

        if (! $this->passesCreditGate($asset, $account, $estimate)) {
            return;
        }

        // --- RESERVE before the provider call (the law) ---
        $reservation = $this->reservations()->reserve($account, (string) $asset->idempotency_key, $estimate);
        $asset->forceFill(['reserved_micro_usd' => $estimate])->save();
        $asset->transitionTo(ProductAsset::STATUS_PROCESSING, ['model' => $config->model, 'provider' => $config->provider]);

        try {
            $submission = $this->caller()->submit($config, $source, $this->vars($product), (string) $asset->idempotency_key);
        } catch (OpenRouterException $e) {
            $this->finalizer()->fail($asset, $reservation, GenerationFailureCode::AI_CALL_FAILED, $e->getMessage(), [
                'error_code' => $e->errorCode,
                'provider_status' => $e->providerStatus,
            ]);

            return;
        } catch (Throwable $e) {
            $this->finalizer()->fail($asset, $reservation, GenerationFailureCode::INTERNAL_ERROR, $e->getMessage());

            return;
        }

        // Persist WHAT we submitted (the ticket is the anti-double-submit anchor + the audit of
        // exactly which model/prompt/price produced this image).
        $asset->forceFill([
            'provider' => $submission->provider,
            'model_used' => $submission->model,
            'provider_request_id' => $submission->ticket?->requestId,
            'provider_meta' => [
                ProductAsset::PROVIDER_META_TICKET => $submission->ticket?->toArray(),
                ProductAsset::PROVIDER_META_FLAT_RATE_MICRO_USD => $submission->flatRatePriceMicroUsd,
            ],
            'meta' => array_merge($asset->meta ?? [], [
                ProductAsset::META_PROMPT_SNAPSHOT => $submission->prompt,
            ]),
        ])->save();

        // ASYNC upstream: the render is in fal's queue. The poller owns it from here — and a blip
        // from now on retries the POLL, never the submit.
        if ($submission->isQueued()) {
            PollProductImageJob::dispatch($this->accountId, $this->siteId, (int) $asset->getKey())
                ->delay(now()->addSeconds(self::FIRST_POLL_DELAY_SECONDS));

            return;
        }

        // SYNC upstream: the image is already here — same job, same laws (store, then charge).
        $this->finalizer()->succeed($asset, $account, $config, $submission->result, $reservation);
    }

    /** The product-photo source (today's path): the stored, stable source_image_url. */
    private function resolvePhotoSource(ProductAsset $asset): ?ImagePayload
    {
        try {
            return ImagePayload::fromUrl((string) $asset->source_image_url);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The FIX source, resolved FRESH at run-time: the private RESULT bytes of source_asset_id read
     * straight off the media disk and handed to the edit model as a data URI. Reading BYTES (not a
     * re-signed url) is disk- and provider-agnostic — no signed-url TTL, and no worker self-HTTP on
     * a local/volume disk. Returns null (→ a clean pre-reserve cancel) if the source is gone,
     * unreadable, or over the payload ceiling.
     */
    private function resolveResultSource(ProductAsset $asset): ?ImagePayload
    {
        $source = $asset->source_asset_id !== null
            ? ProductAsset::query()
                ->where('site_id', $asset->site_id)
                ->whereKey($asset->source_asset_id)
                ->first()
            : null;

        if ($source === null || $source->image_path === null || $source->image_path === '') {
            return null;
        }

        $bytes = app(MediaStorage::class)->get($source->image_path);

        if ($bytes === null) {
            return null;
        }

        try {
            return ImagePayload::fromBytes($bytes, (string) $source->image_mime);
        } catch (Throwable) {
            return null; // e.g. over the size ceiling — cancel cleanly, before the reserve
        }
    }

    /**
     * Row-lock the asset + pre-check the ledger. Returns the locked asset only when it is still
     * pending, uncharged, and NOT already submitted; null (short-circuit) otherwise.
     */
    private function lockAndPrecheck(): ?ProductAsset
    {
        return DB::transaction(function (): ?ProductAsset {
            /** @var ProductAsset $asset */
            $asset = ProductAsset::query()->lockForUpdate()->findOrFail($this->productAssetId);

            if ($asset->isSucceeded() || $this->ledger()->hasCharge((int) $asset->getKey(), self::REFERENCE_TYPE)) {
                return null;
            }

            if (! $asset->isPending()) {
                return null; // processing / failed / cancelled under another trigger
            }

            // THE SUBMIT-ONCE WALL: this asset already has a live provider request. Re-submitting
            // would render (and bill) it twice upstream. Never.
            if ($asset->provider_request_id !== null && $asset->provider_request_id !== '') {
                return null;
            }

            return $asset;
        });
    }

    /** The CreditGate (merchant). On a deny: typed cancel, NO provider call, NO charge. */
    private function passesCreditGate(ProductAsset $asset, Account $account, int $estimateMicroUsd): bool
    {
        $decision = CreditGate::for($account)->assertCanSpend($estimateMicroUsd);

        if ($decision->passed) {
            return true;
        }

        $code = $decision->reason === CreditDenied::REASON_ACCOUNT_INACTIVE
            ? GenerationFailureCode::ACCOUNT_INACTIVE
            : GenerationFailureCode::INSUFFICIENT_CREDITS;

        $this->finalizer()->cancel($asset, $code, $decision->reason, [
            'gate' => 'credit',
            'reason' => $decision->reason,
            'spendable_micro_usd' => $decision->spendableMicroUsd,
            'estimate_micro_usd' => $decision->estimateMicroUsd,
        ]);

        return false;
    }

    /**
     * The prompt placeholders: the product's identity + its REAL data (options / materials /
     * measurements / description, composed by ProductFacts, expanding to nothing when unknown).
     *
     * @return array<string,string|int|float|null>
     */
    private function vars(Product $product): array
    {
        return [
            self::VAR_PRODUCT_NAME => (string) $product->name,
            self::VAR_PRODUCT_TYPE => (string) ($product->product_type ?? ''),
        ] + ProductFacts::for($product, null)->toVars();
    }

    // --- Resolved dependencies (jobs serialize only scalars; resolve at run time) ---

    private function ledger(): CreditLedgerService
    {
        return app(CreditLedgerService::class);
    }

    private function reservations(): ReservationManager
    {
        return app(ReservationManager::class);
    }

    private function resolver(): AiOperationResolver
    {
        return app(AiOperationResolver::class);
    }

    private function estimator(): CreditEstimator
    {
        return app(CreditEstimator::class);
    }

    private function caller(): ProductImageCaller
    {
        return app(ProductImageCaller::class);
    }

    private function finalizer(): ProductImageFinalizer
    {
        return app(ProductImageFinalizer::class);
    }
}
