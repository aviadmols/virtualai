<?php

namespace App\Domain\Generation;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\BannerGenerationCaller;
use App\Domain\Ai\BannerResult;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\OperationConfig;
use App\Domain\Credits\CreditGate;
use App\Domain\Credits\CreditDenied;
use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\CreditMath;
use App\Domain\Credits\Reservation;
use App\Domain\Credits\ReservationManager;
use App\Domain\Media\MediaStorage;
use App\Domain\Media\StoredMedia;
use App\Jobs\TenantAwareJob;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\BannerAsset;
use App\Models\CreditLedger;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GenerateBannerJob — the banner-generation money path (a merchant-billed image generation).
 *
 * Mirrors GenerateTryOnJob's LAW, minus the LeadGate: a banner is a MERCHANT action, so there
 * is exactly ONE gate (CreditGate). The order never varies:
 *   1. row-lock the asset + ledger pre-check (idempotent short-circuit);
 *   2. CreditGate (merchant) — a denial is typed (pending -> cancelled), never a 500;
 *   3. RESERVE the estimate BEFORE the model call;
 *   4. resolve the DB-managed AI bag + call the provider (bytes back);
 *   5. on success: STORE the PUBLIC result, THEN charge = round(cost × multiplier) micro-USD
 *      (idempotent on the key, referencing the banner_asset), link the charge, release;
 *   6. on failure: release the reservation, write NO charge row, mark failed.
 *
 * Four-layer idempotency (never charge twice): ShouldBeUnique + the row lock + the ledger
 * pre-check + the client_request_id segment of the key.
 */
final class GenerateBannerJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const OPERATION_KEY = AiOperation::KEY_BANNER_GENERATION;

    // The charge references the banner_asset row (not a generation).
    private const REFERENCE_TYPE = CreditLedger::REFERENCE_BANNER_ASSET;

    // tries=1: a queue retry must NEVER re-run the money path. timeout must stay UNDER the
    // reservation TTL (300s) so the in-flight reservation can't expire mid-generation.
    public int $tries = 1;

    public int $timeout = 70;

    // The single prompt placeholder the merchant/admin banner prompt may reference.
    private const VAR_BRIEF = 'brief';

    // Actionable failure messages (shared i18n with the try-on money path; en/he 1:1).
    private const MSG_COST_NOT_CONFIGURED = 'platform.generation.cost_not_configured';
    private const MSG_COST_UNAVAILABLE = 'platform.generation.cost_unavailable';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $bannerAssetId,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config('trayon.queues.generations'));
    }

    /**
     * ShouldBeUnique key = the asset's stored idempotency key. Resolved BEFORE handle()
     * binds the tenant, so bind the explicit account_id and read through the normal
     * fail-closed global scope — never withoutGlobalScopes(). Only the key string crosses out.
     */
    public function uniqueId(): string
    {
        return Tenant::run($this->accountId, function (): string {
            $key = BannerAsset::query()
                ->whereKey($this->bannerAssetId)
                ->value('idempotency_key');

            return (string) ($key ?? 'banner:missing:'.$this->bannerAssetId);
        });
    }

    /**
     * Last-resort safety net. process() catches provider/storage failures and finalizes them
     * cleanly, but an exception BEFORE the PROCESSING transition (e.g. the banner_generation op
     * not seeded → the resolver throws, or reserve() throws) — or inside finalizeSuccess — would
     * otherwise escape uncaught and strand the asset in pending/processing, with the real cause
     * only in failed_jobs and the editor's candidate gallery spinning forever. Laravel calls this
     * when handle() throws (tries=1). Bind the tenant, release any still-held reservation (never a
     * leaked hold), and move the asset to a terminal failure carrying the message — so the money
     * path stays honest (an escaped exception NEVER charged) and the merchant sees why.
     *
     * Idempotent: a no-op when the asset already reached a terminal state (a caught failure or a
     * committed success). Respects the guarded machine — a pre-start failure ends 'cancelled'
     * (like a gate/misconfig denial), an in-flight one 'failed'.
     */
    public function failed(Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            $asset = BannerAsset::query()->find($this->bannerAssetId);

            if ($asset === null || $asset->isTerminal()) {
                return;
            }

            // We charge ONLY in finalizeSuccess (which, if it committed, left the asset terminal
            // above). An escaped exception never charged — release any held reservation by key.
            $this->reservations()->releaseByKey($this->accountId, (string) $asset->idempotency_key);

            $message = $e->getMessage();

            $asset->forceFill([
                'failure_code' => GenerationFailureCode::INTERNAL_ERROR,
                'meta' => array_merge($asset->meta ?? [], [BannerAsset::META_FAILURE_MESSAGE => $message]),
            ])->save();

            $terminal = $asset->isPending() ? BannerAsset::STATUS_CANCELLED : BannerAsset::STATUS_FAILED;
            $asset->transitionTo($terminal, ['failure_code' => GenerationFailureCode::INTERNAL_ERROR, 'message' => $message]);
        });
    }

    /** Runs with $this->accountId bound by TenantAwareJob::handle(). */
    protected function process(): void
    {
        $asset = $this->lockAndPrecheck();

        if ($asset === null) {
            return; // already charged / already terminal — idempotent short-circuit
        }

        $site = Site::query()->findOrFail($this->siteId);
        $account = Account::query()->findOrFail($this->accountId);

        $config = $this->resolver()->for(self::OPERATION_KEY, $site, $site->product_category ?: null);

        // Fail EARLY on a flat-rate model with no configured price, before reserving/calling.
        if ($config->flatRatePriceMissing()) {
            $this->failOnMisconfiguration($asset, $config);

            return;
        }

        $estimate = $this->estimator()->estimateMicroUsd($config);

        if (! $this->passesCreditGate($asset, $account, $estimate)) {
            return;
        }

        // --- RESERVE before the model call ---
        $reservation = $this->reservations()->reserve($account, $asset->idempotency_key, $estimate);

        $asset->transitionTo(BannerAsset::STATUS_PROCESSING, ['model' => $config->model]);

        // --- GENERATE ---
        try {
            $result = $this->callProvider($config, $asset);
        } catch (OpenRouterException $e) {
            $this->finalizeFailure($asset, $reservation, GenerationFailureCode::AI_CALL_FAILED, $e->getMessage(), [
                'error_code' => $e->errorCode,
                'provider_status' => $e->providerStatus,
            ]);

            return;
        } catch (Throwable $e) {
            $this->finalizeFailure($asset, $reservation, GenerationFailureCode::INTERNAL_ERROR, $e->getMessage());

            return;
        }

        // Cost must be real to charge honestly (defense in depth on both conditions).
        if (! $result->cost->available || $result->cost->costUsd === null) {
            $this->finalizeFailure($asset, $reservation, GenerationFailureCode::COST_UNAVAILABLE, (string) __(self::MSG_COST_UNAVAILABLE));

            return;
        }

        // --- STORE the PUBLIC result BEFORE charging ---
        try {
            $stored = $this->media()->storeBannerResult(
                $this->accountId,
                $this->siteId,
                $asset->getKey(),
                $result->imageBytes,
                $result->mimeType,
            );
        } catch (Throwable $e) {
            $this->finalizeFailure($asset, $reservation, GenerationFailureCode::STORAGE_FAILED, $e->getMessage());

            return;
        }

        $this->finalizeSuccess($asset, $account, $config, $result, $stored, $reservation);
    }

    /**
     * Row-lock the asset + pre-check the ledger. Returns the locked asset when still pending
     * and uncharged; null (idempotent short-circuit) when already charged/terminal.
     */
    private function lockAndPrecheck(): ?BannerAsset
    {
        return DB::transaction(function (): ?BannerAsset {
            /** @var BannerAsset $asset */
            $asset = BannerAsset::query()->lockForUpdate()->findOrFail($this->bannerAssetId);

            if ($asset->isSucceeded() || $this->ledger()->hasCharge($asset->getKey(), self::REFERENCE_TYPE)) {
                return null;
            }

            if (! $asset->isPending()) {
                return null; // processing/failed/cancelled under another trigger
            }

            return $asset;
        });
    }

    /** The CreditGate (merchant). On a deny: typed cancel, NO model call, NO charge. */
    private function passesCreditGate(BannerAsset $asset, Account $account, int $estimateMicroUsd): bool
    {
        $decision = CreditGate::for($account)->assertCanSpend($estimateMicroUsd);

        if ($decision->passed) {
            return true;
        }

        $code = $decision->reason === CreditDenied::REASON_ACCOUNT_INACTIVE
            ? GenerationFailureCode::ACCOUNT_INACTIVE
            : GenerationFailureCode::INSUFFICIENT_CREDITS;

        // Pre-processing denial -> pending -> cancelled. No charge, no reservation taken.
        $this->cancelOnGate($asset, $code, [
            'gate' => 'credit',
            'reason' => $decision->reason,
            'spendable_micro_usd' => $decision->spendableMicroUsd,
            'estimate_micro_usd' => $decision->estimateMicroUsd,
        ]);

        return false;
    }

    /**
     * Cancel an asset a gate refused to start (pending -> cancelled). Stamps failure_code; no
     * model ran and no reservation was taken, so nothing to release or charge.
     *
     * @param  array<string,mixed>  $details
     */
    private function cancelOnGate(BannerAsset $asset, string $code, array $details): void
    {
        $asset->failure_code = $code;
        $asset->save();
        $asset->transitionTo(BannerAsset::STATUS_CANCELLED, ['failure_code' => $code] + $details);
    }

    /**
     * Fail an asset whose resolved config could never charge honestly (a flat-rate model with
     * no price), detected BEFORE the reserve/call. pending -> cancelled, like a gate denial.
     */
    private function failOnMisconfiguration(BannerAsset $asset, OperationConfig $config): void
    {
        $message = (string) __(self::MSG_COST_NOT_CONFIGURED, ['model' => $config->model]);

        $asset->forceFill([
            'failure_code' => GenerationFailureCode::AI_COST_NOT_CONFIGURED,
            'meta' => array_merge($asset->meta ?? [], [BannerAsset::META_FAILURE_MESSAGE => $message]),
        ])->save();

        $asset->transitionTo(BannerAsset::STATUS_CANCELLED, [
            'failure_code' => GenerationFailureCode::AI_COST_NOT_CONFIGURED,
            'model' => $config->model,
        ]);
    }

    /**
     * Resolve the reference image (optional) + assemble the brief var, snapshot the prompt,
     * and call the provider. Prompt substitution happens INSIDE the caller (strtr).
     */
    private function callProvider(OperationConfig $config, BannerAsset $asset): BannerResult
    {
        $reference = $this->loadReference($asset);

        $vars = [self::VAR_BRIEF => (string) $asset->brief];

        // Snapshot exactly what we asked the model to render (audit of the resolver output).
        $asset->forceFill([
            'model_used' => $config->model,
            'meta' => array_merge($asset->meta ?? [], [
                BannerAsset::META_PROMPT_SNAPSHOT => $config->substituteUser($vars),
            ]),
        ])->save();

        return $this->caller()->generate($config, $reference, $vars);
    }

    /** The optional reference upload as an ImagePayload (signed URL), or null when none. */
    private function loadReference(BannerAsset $asset): ?ImagePayload
    {
        if ($asset->source_image_path === null || $asset->source_image_path === '') {
            return null;
        }

        $signed = $this->media()->signedUrl($asset->source_image_path);

        return $signed !== null ? ImagePayload::fromUrl($signed) : null;
    }

    /**
     * SUCCESS finalize, in a fresh row-locked transaction. Charge ONLY here, ONLY after the
     * result is stored, ONLY once (idempotent on the key, referencing the banner_asset).
     */
    private function finalizeSuccess(
        BannerAsset $asset,
        Account $account,
        OperationConfig $config,
        BannerResult $result,
        StoredMedia $stored,
        Reservation $reservation,
    ): void {
        $costUsd = $result->cost->costUsd;

        if ($costUsd === null) {
            $this->finalizeFailure($asset, $reservation, GenerationFailureCode::COST_UNAVAILABLE, (string) __(self::MSG_COST_UNAVAILABLE));

            return;
        }

        $multiplier = $config->creditMultiplier ?? CreditMath::multiplierFor($config->operationKey);
        $chargeMicro = CreditMath::chargeMicroUsd($costUsd, $multiplier);
        $actualCostMicro = CreditMath::usdToMicro($costUsd);
        [$width, $height] = $this->imageDimensions($result->imageBytes);

        DB::transaction(function () use ($asset, $account, $result, $stored, $reservation, $chargeMicro, $actualCostMicro, $width, $height): void {
            /** @var BannerAsset $locked */
            $locked = BannerAsset::query()->lockForUpdate()->findOrFail($asset->getKey());

            // A racing finalize already charged -> release + return.
            if ($this->ledger()->hasCharge($locked->getKey(), self::REFERENCE_TYPE)) {
                $this->reservations()->release($reservation);

                return;
            }

            $charge = $this->ledger()->charge(
                account: $account,
                chargeMicroUsd: $chargeMicro,
                actualCostMicroUsd: $actualCostMicro,
                idempotencyKey: $locked->idempotency_key,
                generationId: $locked->getKey(),
                reservation: $reservation,
                meta: ['model_used' => $result->modelUsed],
                referenceType: self::REFERENCE_TYPE,
            );

            $locked->forceFill([
                'image_path' => $stored->path,
                'image_mime' => $result->mimeType,
                'image_width' => $width,
                'image_height' => $height,
                'model_used' => $result->modelUsed,
                'actual_cost_micro_usd' => $actualCostMicro,
                'charge_ledger_id' => $charge->getKey(),
                'meta' => array_merge($locked->meta ?? [], [
                    BannerAsset::META_OPENROUTER_GENERATION_ID => $result->openrouterGenerationId,
                ]),
            ])->save();

            $locked->transitionTo(BannerAsset::STATUS_SUCCEEDED, [
                'charge_micro_usd' => $chargeMicro,
                'actual_cost_micro_usd' => $actualCostMicro,
                'model_used' => $result->modelUsed,
            ]);
        });
    }

    /**
     * FAILURE finalize: release the reservation, write NO charge row, mark failed. The
     * merchant is never billed for a failed banner generation.
     *
     * @param  array<string,mixed>  $details
     */
    private function finalizeFailure(
        BannerAsset $asset,
        Reservation $reservation,
        string $code,
        string $message,
        array $details = [],
    ): void {
        $this->ledger()->release($reservation, ['failure_code' => $code]);

        $asset->forceFill([
            'failure_code' => $code,
            'meta' => array_merge($asset->meta ?? [], [BannerAsset::META_FAILURE_MESSAGE => $message]),
        ])->save();

        $asset->transitionTo(BannerAsset::STATUS_FAILED, ['failure_code' => $code, 'message' => $message] + $details);
    }

    /** Best-effort pixel dimensions of the result (for the widget CLS box); null if unknown. */
    private function imageDimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if (is_array($info) && isset($info[0], $info[1])) {
            return [(int) $info[0], (int) $info[1]];
        }

        return [null, null];
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

    private function caller(): BannerGenerationCaller
    {
        return app(BannerGenerationCaller::class);
    }

    private function media(): MediaStorage
    {
        return app(MediaStorage::class);
    }
}
