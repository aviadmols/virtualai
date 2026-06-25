<?php

namespace App\Domain\Generation;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\TryOnGenerationCaller;
use App\Domain\Ai\TryOnResult;
use App\Domain\Credits\CreditGate;
use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\CreditMath;
use App\Domain\Credits\Reservation;
use App\Domain\Credits\ReservationManager;
use App\Domain\Leads\LeadGate;
use App\Domain\Media\MediaStorage;
use App\Jobs\TenantAwareJob;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GenerateTryOnJob — THE money path. The ONLY place a credit is charged.
 *
 * Extends TenantAwareJob (explicit constructor account_id; handle() binds the tenant
 * via Tenant::run which clears in finally — TS-TENANCY-001), and is ShouldBeUnique on
 * the generation idempotency key so a double-dispatch is dropped before it runs.
 *
 * The order is the LAW (ARCHITECTURE.md "the money path"), and it never varies:
 *   1. row-lock the generation + ledger pre-check (idempotent short-circuit);
 *   2. TWO independent gates — LeadGate (end user) AND CreditGate (merchant) — both
 *      must pass; a denial is a typed result + activity trace + clean end, never a 500;
 *   3. RESERVE the estimated max charge BEFORE the model call;
 *   4. resolve the DB-managed AI bag + call OpenRouter (bytes back);
 *   5. on success: STORE the result, THEN charge = round(cost × multiplier) micro-USD
 *      (idempotent on the same key), link charge_ledger_id, release the reservation,
 *      bump the lead funnel + free-tries count;
 *   6. on failure: release the reservation, write NO charge row, mark failed.
 *
 * Four-layer idempotency (never charge twice): ShouldBeUnique + the row lock + the
 * ledger pre-check (CreditLedgerService no-ops if a charge exists) + the
 * client_request_id segment of the key.
 */
final class GenerateTryOnJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const OPERATION_KEY = 'try_on_generation';

    // tries=1: a queue retry must NEVER re-run the money path (a blind retry risks a
    // double spend). The four-layer wall would catch it, but no-retry is the floor.
    // Mirrors the Horizon generations supervisor (config/horizon.php GEN_TRIES=1).
    public int $tries = 1;

    // The job timeout MUST stay UNDER the reservation TTL (config reservation_ttl=300)
    // so the in-flight reservation can never expire mid-generation. 70s mirrors the
    // Horizon generations supervisor GEN_TIMEOUT and leaves ample headroom under 300s.
    // The invariant (timeout < reservation_ttl) is asserted by a config-check test.
    public int $timeout = 70;

    // Prompt placeholders the merchant/admin prompt template may reference. Substituted
    // with strtr by the caller (OperationConfig::substituteUser) — NEVER Blade::render.
    // Keys mirror the seeded global try_on_generation prompt ({{product_name}},
    // {{variant}}, {{height}}); an unknown placeholder is simply left literal.
    private const VAR_PRODUCT_NAME = 'product_name';
    private const VAR_PRODUCT_TYPE = 'product_type';
    private const VAR_VARIANT = 'variant';
    private const VAR_HEIGHT = 'height';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $generationId,
    ) {
        parent::__construct($accountId);
        $this->onQueue(Q_GENERATIONS);
    }

    /**
     * ShouldBeUnique key = the generation's stored idempotency key. A second dispatch
     * for the same generation is dropped before handle() runs (LAYER 1 of the wall).
     *
     * Unique-lock resolution runs BEFORE handle() binds the tenant, so we bind the
     * job's EXPLICIT account_id via Tenant::run and read the key through the NORMAL
     * fail-closed global scope — never withoutGlobalScopes(). Only the key string (a
     * routing fact) crosses out, the same shape as the TS-CREDITS-004 purchase router.
     */
    public function uniqueId(): string
    {
        return Tenant::run($this->accountId, function (): string {
            $key = Generation::query()
                ->whereKey($this->generationId)
                ->value('idempotency_key');

            return (string) ($key ?? 'generation:missing:'.$this->generationId);
        });
    }

    /** Runs with $this->accountId bound by TenantAwareJob::handle(). */
    protected function process(): void
    {
        $generation = $this->lockAndPrecheck();

        if ($generation === null) {
            return; // already charged / already terminal — idempotent short-circuit
        }

        $site = Site::query()->findOrFail($this->siteId);
        $endUser = EndUser::query()->findOrFail($generation->end_user_id);
        $product = Product::query()->findOrFail($generation->product_id);

        // --- TWO INDEPENDENT GATES (both must pass; each denial is typed, never a 500) ---
        if (! $this->passesLeadGate($generation, $site, $endUser)) {
            return;
        }

        $config = $this->resolver()->for(self::OPERATION_KEY, $site, $product->product_type);
        $estimate = $this->estimator()->estimateMicroUsd($config);

        if (! $this->passesCreditGate($generation, $endUser, $estimate)) {
            return;
        }

        // --- RESERVE before the model call (the held estimate already subtracted from spendable) ---
        $reservation = $this->reservations()->reserve($endUser->account, $generation->idempotency_key, $estimate);

        $this->recordActivity(ActivityEvent::KIND_GENERATION_RESERVED, $generation, [
            'estimate_micro_usd' => $estimate,
        ]);

        $generation->transitionTo(Generation::STATUS_PROCESSING);
        $this->recordActivity(ActivityEvent::KIND_GENERATION_PROCESSING, $generation, [
            'model' => $config->model,
        ]);

        // --- GENERATE (ai-openrouter owns the OpenRouter call; bytes back) ---
        try {
            $result = $this->callOpenRouter($config, $generation, $product);
        } catch (OpenRouterException $e) {
            $this->finalizeFailure($generation, $reservation, GenerationFailureCode::AI_CALL_FAILED, $e->getMessage(), [
                'error_code' => $e->errorCode,
                'provider_status' => $e->providerStatus,
            ]);

            return;
        } catch (Throwable $e) {
            $this->finalizeFailure($generation, $reservation, GenerationFailureCode::INTERNAL_ERROR, $e->getMessage());

            return;
        }

        // Cost must be real to charge honestly — never invent a number. ParsedCost
        // already enforces "available => non-null cost" in its constructor, but we
        // guard explicitly on BOTH conditions here (defense in depth) so a null cost
        // can never reach CreditMath::chargeMicroUsd() on ANY path. On a missing cost:
        // release the reservation, write NO charge row, and do NOT consume a free try.
        if (! $result->cost->available || $result->cost->costUsd === null) {
            $this->finalizeFailure($generation, $reservation, GenerationFailureCode::COST_UNAVAILABLE, 'OpenRouter returned no usable cost.');

            return;
        }

        // --- STORE the result BEFORE charging (no charge without a stored result) ---
        try {
            $stored = $this->media()->storeResult(
                $this->accountId,
                $this->siteId,
                $generation->getKey(),
                $result->imageBytes,
                $result->mimeType,
            );
        } catch (Throwable $e) {
            $this->finalizeFailure($generation, $reservation, GenerationFailureCode::STORAGE_FAILED, $e->getMessage());

            return;
        }

        $this->finalizeSuccess($generation, $endUser, $config, $result, $stored, $reservation);
    }

    /**
     * LAYER 2 + 3: row-lock the generation inside a transaction and pre-check the
     * ledger. Returns the locked generation when it is still pending and uncharged;
     * returns null (an idempotent short-circuit) when it is already charged/terminal
     * or already processing under another trigger — so OpenRouter is never re-called.
     */
    private function lockAndPrecheck(): ?Generation
    {
        return DB::transaction(function (): ?Generation {
            /** @var Generation $generation */
            $generation = Generation::query()->lockForUpdate()->findOrFail($this->generationId);

            // Already charged (a racing/duplicate trigger) or already in a terminal
            // state -> do nothing. Never re-call OpenRouter, never re-charge.
            if ($generation->isSucceeded() || $this->ledger()->hasCharge($generation->getKey())) {
                return null;
            }

            if (! $generation->isPending()) {
                return null; // processing/failed/cancelled under another trigger
            }

            $this->recordActivity(ActivityEvent::KIND_GENERATION_REQUESTED, $generation);

            return $generation;
        });
    }

    /** The LeadGate (end user). On a deny: typed failure, NO model call, NO charge. */
    private function passesLeadGate(Generation $generation, Site $site, EndUser $endUser): bool
    {
        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        if ($decision->allowed) {
            return true;
        }

        // The reason maps 1:1 to a failure code; the widget renders the signup form.
        $code = $decision->reason === \App\Domain\Leads\LeadDecision::REASON_POST_SIGNUP_LIMIT
            ? GenerationFailureCode::POST_SIGNUP_LIMIT
            : GenerationFailureCode::SIGNUP_REQUIRED;

        // A gate denial happens BEFORE processing — the model never ran, so this is a
        // CANCELLED start (pending -> cancelled, the only legal pre-processing exit),
        // NOT a `failed` (which the locked machine reserves for processing -> failed).
        // The reason lives in failure_code + the activity trace either way.
        $this->cancelOnGate($generation, $code, ActivityEvent::KIND_LEAD_GATE_BLOCKED, [
            'gate' => 'lead',
            'reason' => $decision->reason,
        ]);

        return false;
    }

    /** The CreditGate (merchant). On a deny: typed failure, NO model call, NO charge. */
    private function passesCreditGate(Generation $generation, EndUser $endUser, int $estimateMicroUsd): bool
    {
        $decision = CreditGate::for($endUser->account)->assertCanSpend($estimateMicroUsd);

        if ($decision->passed) {
            return true;
        }

        $code = $decision->reason === \App\Domain\Credits\CreditDenied::REASON_ACCOUNT_INACTIVE
            ? GenerationFailureCode::ACCOUNT_INACTIVE
            : GenerationFailureCode::INSUFFICIENT_CREDITS;

        // Pre-processing denial -> pending -> cancelled (see passesLeadGate). No charge,
        // no reservation was taken (the gate runs before reserve), so nothing to release.
        $this->cancelOnGate($generation, $code, ActivityEvent::KIND_CREDIT_GATE_BLOCKED, [
            'gate' => 'credit',
            'reason' => $decision->reason,
            'spendable_micro_usd' => $decision->spendableMicroUsd,
            'estimate_micro_usd' => $decision->estimateMicroUsd,
        ]);

        return false;
    }

    /**
     * Cancel a generation that a gate refused to start (pending -> cancelled). Stamps
     * the failure_code for the reason and records the gate-blocked trace. No model
     * call ran and no reservation was taken, so there is nothing to release or charge.
     *
     * @param  array<string,mixed>  $details
     */
    private function cancelOnGate(Generation $generation, string $code, string $kind, array $details): void
    {
        $generation->failure_code = $code;
        $generation->save();
        $generation->transitionTo(Generation::STATUS_CANCELLED, $details);

        $this->recordActivity($kind, $generation, $details);
    }

    /**
     * Resolve the AI bag, assemble the prompt vars, and call the OpenRouter try-on.
     * The shopper photo comes from the stored source; the variant image from the
     * selected variant. Prompt substitution happens INSIDE the caller (strtr).
     */
    private function callOpenRouter(OperationConfig $config, Generation $generation, Product $product): TryOnResult
    {
        $variant = $generation->variant; // the SELECTED variant being tried on

        $shopper = $this->loadSourceImage($generation);
        $variantImage = ImagePayload::fromUrl($variant?->image_url ?: $product->main_image_url);

        $vars = [
            self::VAR_PRODUCT_NAME => (string) $product->name,
            self::VAR_PRODUCT_TYPE => (string) $product->product_type,
            self::VAR_VARIANT => $this->variantLabel($generation),
            self::VAR_HEIGHT => (string) ($generation->meta[Generation::META_HEIGHT] ?? ''),
        ];

        // Snapshot exactly what we asked the model to render (audit of the resolver output).
        $generation->forceFill([
            'model_used' => $config->model,
            'meta' => array_merge($generation->meta ?? [], [
                Generation::META_PROMPT_SNAPSHOT => $config->substituteUser($vars),
            ]),
        ])->save();

        return $this->caller()->generate($config, $shopper, $variantImage, $vars);
    }

    /** Load the stored shopper photo as an ImagePayload (signed URL — preferred over base64). */
    private function loadSourceImage(Generation $generation): ImagePayload
    {
        $signed = $this->media()->signedUrl($generation->source_image_path);

        if ($signed !== null) {
            return ImagePayload::fromUrl($signed);
        }

        // Fallback: read the bytes and send inline (still server-side; never the key).
        throw new OpenRouterException(
            OpenRouterException::CODE_BAD_REQUEST,
            'Source image is missing for the generation.',
        );
    }

    /** A human/label form of the selected variant for the prompt + activity. */
    private function variantLabel(Generation $generation): string
    {
        $options = (array) ($generation->meta[Generation::META_VARIANT_SNAPSHOT] ?? ($generation->variant?->options ?? []));

        $parts = [];
        foreach ($options as $axis => $value) {
            $parts[] = $axis.': '.$value;
        }

        return implode(', ', $parts);
    }

    /**
     * SUCCESS finalize, in a fresh row-locked transaction. Charge ONLY here, ONLY
     * after the result is stored, ONLY once (idempotent on the key). Then link the
     * charge, release the reservation, advance the lead funnel + free-tries count.
     */
    private function finalizeSuccess(
        Generation $generation,
        EndUser $endUser,
        OperationConfig $config,
        TryOnResult $result,
        \App\Domain\Media\StoredMedia $stored,
        Reservation $reservation,
    ): void {
        // Defense in depth: finalizeSuccess is only reached past the available/non-null
        // guard in process(), but pin the precondition locally too so a future caller
        // can never feed a null cost into CreditMath::chargeMicroUsd().
        $costUsd = $result->cost->costUsd;

        if ($costUsd === null) {
            $this->finalizeFailure($generation, $reservation, GenerationFailureCode::COST_UNAVAILABLE, 'OpenRouter returned no usable cost.');

            return;
        }

        $multiplier = $config->creditMultiplier ?? CreditMath::multiplierFor($config->operationKey);
        $chargeMicro = CreditMath::chargeMicroUsd($costUsd, $multiplier);
        $actualCostMicro = CreditMath::usdToMicro($costUsd);

        DB::transaction(function () use ($generation, $endUser, $result, $stored, $reservation, $chargeMicro, $actualCostMicro): void {
            /** @var Generation $locked */
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->getKey());

            // A racing finalize already charged this generation -> release + return.
            if ($this->ledger()->hasCharge($locked->getKey())) {
                $this->reservations()->release($reservation);

                return;
            }

            // The charge: ONE negative ledger row at the selling value, idempotent on
            // the generation key. CreditLedgerService releases the reservation too.
            $charge = $this->ledger()->charge(
                account: $endUser->account,
                chargeMicroUsd: $chargeMicro,
                actualCostMicroUsd: $actualCostMicro,
                idempotencyKey: $locked->idempotency_key,
                generationId: $locked->getKey(),
                reservation: $reservation,
                meta: ['model_used' => $result->modelUsed],
            );

            $locked->forceFill([
                'result_image_path' => $stored->path,
                'model_used' => $result->modelUsed,
                'actual_cost_micro_usd' => $actualCostMicro,
                'charge_ledger_id' => $charge->getKey(),
                'meta' => array_merge($locked->meta ?? [], [
                    Generation::META_OPENROUTER_GENERATION_ID => $result->openrouterGenerationId,
                ]),
            ])->save();

            $locked->transitionTo(Generation::STATUS_SUCCEEDED, [
                'charge_micro_usd' => $chargeMicro,
                'actual_cost_micro_usd' => $actualCostMicro,
            ]);

            // Advance the lead funnel + consume ONE free try — ONLY on a charged success.
            // A failed try-on consumes neither a credit nor a free try (item 6).
            if ($endUser->status === EndUser::STATUS_NEW) {
                $endUser->transitionTo(EndUser::STATUS_GENERATED);
            }
            $endUser->increment('generations_used');

            $this->recordActivity(ActivityEvent::KIND_GENERATION_SUCCEEDED, $locked, [
                'charge_micro_usd' => $chargeMicro,
                'actual_cost_micro_usd' => $actualCostMicro,
                'model_used' => $result->modelUsed,
            ]);
        });
    }

    /**
     * FAILURE finalize: release the reservation, write NO charge row, mark failed.
     * The merchant is never billed for a failed try-on; the free-tries count is NOT
     * consumed (only a charged success consumes a free try).
     *
     * @param  array<string,mixed>  $details
     */
    private function finalizeFailure(
        Generation $generation,
        Reservation $reservation,
        string $code,
        string $message,
        array $details = [],
    ): void {
        // release() writes a reservation-released activity + decrements reserved; it
        // never writes a charge row. Done on a fresh read of the generation.
        $this->ledger()->release($reservation, ['failure_code' => $code]);

        $generation->forceFill([
            'failure_code' => $code,
            'meta' => array_merge($generation->meta ?? [], [
                Generation::META_FAILURE_MESSAGE => $message,
            ]),
        ])->save();

        $generation->transitionTo(Generation::STATUS_FAILED, ['failure_code' => $code] + $details);

        $this->recordActivity(ActivityEvent::KIND_GENERATION_FAILED, $generation, ['failure_code' => $code] + $details);
    }

    /**
     * @param  array<string,mixed>  $details
     */
    private function recordActivity(string $kind, Generation $generation, array $details = []): void
    {
        $this->activity()->record(
            kind: $kind,
            subject: $generation,
            details: $details,
            siteId: $generation->site_id,
        );
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

    private function resolver(): \App\Domain\Ai\AiOperationResolver
    {
        return app(\App\Domain\Ai\AiOperationResolver::class);
    }

    private function estimator(): CreditEstimator
    {
        return app(CreditEstimator::class);
    }

    private function caller(): TryOnGenerationCaller
    {
        return app(TryOnGenerationCaller::class);
    }

    private function media(): MediaStorage
    {
        return app(MediaStorage::class);
    }

    private function activity(): ActivityRecorder
    {
        return app(ActivityRecorder::class);
    }
}
