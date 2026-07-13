<?php

namespace App\Domain\ProductImages;

use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\ProductImageResult;
use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\CreditMath;
use App\Domain\Credits\Reservation;
use App\Domain\Credits\ReservationManager;
use App\Domain\Generation\GenerationFailureCode;
use App\Domain\Media\MediaStorage;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ProductImageFinalizer — the TERMINAL half of the product-image money path, shared by BOTH
 * entry shapes (the async poller's finish, and the synchronous upstream that finishes inside
 * the submit job). One implementation means the money laws cannot drift between them.
 *
 * The laws, in this exact order:
 *
 *   SUCCESS  cost must be REAL (an unavailable cost is a failure, never a guess)
 *         -> STORE the result bytes FIRST (never a credit for an image that does not exist)
 *         -> then, in a FRESH row-locked transaction: charge ONCE
 *            = round(real_cost × multiplier) micro-USD, idempotent on the asset key,
 *            referencing product_asset -> link the ledger row -> release the reservation
 *         -> succeeded (awaiting the merchant's review).
 *
 *   FAILURE  release the reservation, write NO charge row, mark the asset failed/cancelled.
 *            A failed render is never billed — and a later REJECTION is not a failure: the AI
 *            ran, the provider billed us, and the charge stands (the studio UI says so).
 *
 * Every terminal path records exactly ONE batch counter, so the merchant's progress bar always
 * settles at 100%.
 */
final class ProductImageFinalizer
{
    // === CONSTANTS ===
    private const REFERENCE_TYPE = CreditLedger::REFERENCE_PRODUCT_ASSET;

    private const MSG_COST_UNAVAILABLE = 'platform.generation.cost_unavailable';

    public function __construct(
        private readonly CreditLedgerService $ledger,
        private readonly ReservationManager $reservations,
        private readonly MediaStorage $media,
    ) {}

    /**
     * Finish a SUCCEEDED render: store, then charge once. Returns true when this call is the
     * one that charged (a racing finalize releases and returns false).
     */
    public function succeed(
        ProductAsset $asset,
        Account $account,
        OperationConfig $config,
        ProductImageResult $result,
        Reservation $reservation,
    ): bool {
        $costUsd = $result->cost->costUsd;

        // Defense in depth on BOTH conditions: a null cost can never present as available
        // (ParsedCost enforces it), and we still refuse to charge if either is off.
        if (! $result->cost->available || $costUsd === null) {
            $this->fail($asset, $reservation, GenerationFailureCode::COST_UNAVAILABLE, (string) __(self::MSG_COST_UNAVAILABLE));

            return false;
        }

        // STORE BEFORE CHARGE — a credit is never debited for an image the merchant cannot see.
        try {
            $stored = $this->media->storeProductAsset(
                (int) $asset->account_id,
                (int) $asset->site_id,
                (int) $asset->getKey(),
                $result->imageBytes,
                $result->mimeType,
            );
        } catch (Throwable $e) {
            $this->fail($asset, $reservation, GenerationFailureCode::STORAGE_FAILED, $e->getMessage());

            return false;
        }

        $multiplier = $config->creditMultiplier ?? CreditMath::multiplierFor($config->operationKey);
        $chargeMicro = CreditMath::chargeMicroUsd($costUsd, $multiplier);
        $actualCostMicro = CreditMath::usdToMicro($costUsd);
        [$width, $height] = $this->dimensions($result->imageBytes);

        $charged = DB::transaction(function () use ($asset, $account, $result, $stored, $reservation, $chargeMicro, $actualCostMicro, $width, $height): bool {
            /** @var ProductAsset $locked */
            $locked = ProductAsset::query()->lockForUpdate()->findOrFail($asset->getKey());

            // THE DOUBLE-CHARGE WALL: a racing finalize already charged this asset -> release
            // the (idempotent) hold and stop. No second ledger row, ever.
            if ($locked->isSucceeded() || $this->ledger->hasCharge((int) $locked->getKey(), self::REFERENCE_TYPE)) {
                $this->reservations->release($reservation);

                return false;
            }

            $charge = $this->ledger->charge(
                account: $account,
                chargeMicroUsd: $chargeMicro,
                actualCostMicroUsd: $actualCostMicro,
                idempotencyKey: (string) $locked->idempotency_key,
                generationId: (int) $locked->getKey(),
                reservation: $reservation,
                meta: ['model_used' => $result->modelUsed, 'provider' => $result->provider],
                referenceType: self::REFERENCE_TYPE,
            );

            $locked->forceFill([
                'image_path' => $stored->path,
                'image_mime' => $result->mimeType,
                'image_width' => $width,
                'image_height' => $height,
                'model_used' => $result->modelUsed,
                'provider' => $result->provider,
                'actual_cost_micro_usd' => $actualCostMicro,
                'charge_micro_usd' => $chargeMicro,
                'charge_ledger_id' => $charge->getKey(),
                'reserved_micro_usd' => 0,
                'meta' => array_merge($locked->meta ?? [], [
                    ProductAsset::META_PROVIDER_GENERATION_ID => $result->providerGenerationId,
                ]),
            ])->save();

            $locked->transitionTo(ProductAsset::STATUS_SUCCEEDED, [
                'charge_micro_usd' => $chargeMicro,
                'actual_cost_micro_usd' => $actualCostMicro,
                'model_used' => $result->modelUsed,
            ]);

            $asset->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($charged) {
            $this->batchOutcome($asset, ProductImageBatch::COUNTER_SUCCEEDED, $chargeMicro);
        }

        return $charged;
    }

    /**
     * FAILURE finalize (the render started and did not produce a billable image): release the
     * hold, write NO charge row, mark failed. Idempotent — a terminal asset is left alone.
     *
     * @param  array<string,mixed>  $details
     */
    public function fail(
        ProductAsset $asset,
        Reservation $reservation,
        string $code,
        string $message,
        array $details = [],
    ): void {
        $this->ledger->release($reservation, ['failure_code' => $code]);

        if ($asset->isTerminal()) {
            return;
        }

        $asset->forceFill([
            'failure_code' => $code,
            'reserved_micro_usd' => 0,
            'meta' => array_merge($asset->meta ?? [], [ProductAsset::META_FAILURE_MESSAGE => $message]),
        ])->save();

        // A refusal BEFORE the render started is a cancelled start (the guarded machine has no
        // pending -> failed edge); a failure in flight is a failed one.
        $terminal = $asset->isPending() ? ProductAsset::STATUS_CANCELLED : ProductAsset::STATUS_FAILED;
        $asset->transitionTo($terminal, ['failure_code' => $code, 'message' => $message] + $details);

        $this->batchOutcome($asset, ProductImageBatch::COUNTER_FAILED);
    }

    /**
     * A refusal decided BEFORE anything was reserved or called (a gate denial, a missing source
     * photo, a model with no configured price): pending -> cancelled. Nothing to release, and
     * by construction nothing to charge.
     *
     * @param  array<string,mixed>  $details
     */
    public function cancel(ProductAsset $asset, string $code, string $message, array $details = []): void
    {
        if ($asset->isTerminal()) {
            return;
        }

        $asset->forceFill([
            'failure_code' => $code,
            'meta' => array_merge($asset->meta ?? [], [ProductAsset::META_FAILURE_MESSAGE => $message]),
        ])->save();

        $asset->transitionTo(ProductAsset::STATUS_CANCELLED, ['failure_code' => $code] + $details);

        $this->batchOutcome($asset, ProductImageBatch::COUNTER_FAILED);
    }

    /** Record this asset's terminal outcome on its batch (row-locked; never lost). */
    private function batchOutcome(ProductAsset $asset, string $counter, int $chargedMicroUsd = 0): void
    {
        $batch = ProductImageBatch::query()->find($asset->batch_id);

        $batch?->recordOutcome($counter, $chargedMicroUsd);
    }

    /**
     * Best-effort pixel dimensions of the result (the review grid's CLS box).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if (is_array($info) && isset($info[0], $info[1])) {
            return [(int) $info[0], (int) $info[1]];
        }

        return [null, null];
    }
}
