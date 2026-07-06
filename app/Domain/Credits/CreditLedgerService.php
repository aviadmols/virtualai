<?php

namespace App\Domain\Credits;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CreditLedgerService — THE ONLY writer of credit_ledger rows and the ONLY mutator
 * of accounts.balance_micro_usd. Every method runs in a DB transaction with a row
 * lock on the account, so the ledger row + balance + balance_after snapshot move
 * together and can never drift.
 *
 * The money laws, encoded here:
 *  - No charge without a ledger row (this writes the row that IS the charge).
 *  - Append-only: a correction is a NEW row (refund reverses; adjustment is ±).
 *  - Integer micro-USD only; the selling value is computed once by CreditMath.
 *  - Deterministic idempotency key + the unique DB index + a ledger pre-check =
 *    a duplicate charge is impossible. A second charge for the same key is a
 *    silent no-op (the existing row is returned), NEVER a second debit.
 *
 * Phase 6's GenerateTryOnJob calls charge() on success and release() on failure.
 * saas-credits-billing's purchase rail calls purchase() through this service —
 * nothing writes the ledger directly.
 */
final class CreditLedgerService
{
    // === CONSTANTS ===
    private const BALANCE_COLUMN = 'balance_micro_usd';

    public function __construct(
        private readonly ReservationManager $reservations,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * A grant row (opening credit or an admin gift). Positive amount. Idempotent on
     * the idempotency key, so the opening grant runs exactly once per account.
     *
     * @param  array<string,mixed>  $meta
     */
    public function grant(
        Account $account,
        int $amountMicroUsd,
        string $idempotencyKey,
        string $description = '',
        array $meta = [],
        string $kind = ActivityEvent::KIND_CREDIT_GRANT,
    ): CreditLedger {
        return $this->append(
            account: $account,
            type: CreditLedger::TYPE_GRANT,
            amountMicroUsd: abs($amountMicroUsd),
            idempotencyKey: $idempotencyKey,
            meta: ['description' => $description] + $meta,
            activityKind: $kind,
            subject: $account,
        );
    }

    /**
     * A purchase row (credits bought through the purchase rail). Positive amount.
     * saas-credits-billing calls this with a purchase:{account}:{provider}:{ref} key.
     *
     * @param  array<string,mixed>  $meta
     */
    public function purchase(
        Account $account,
        int $amountMicroUsd,
        string $idempotencyKey,
        string $description = '',
        array $meta = [],
    ): CreditLedger {
        return $this->append(
            account: $account,
            type: CreditLedger::TYPE_PURCHASE,
            amountMicroUsd: abs($amountMicroUsd),
            idempotencyKey: $idempotencyKey,
            meta: ['description' => $description] + $meta,
            activityKind: ActivityEvent::KIND_CREDIT_GRANT,
            subject: $account,
        );
    }

    /**
     * The debit on a succeeded generation. Writes ONE negative `charge` row at the
     * resolved selling value, decrements the balance, releases the reservation.
     *
     * Four-layer idempotency (the double-charge wall):
     *  1. the unique idempotency_key index (DB-level impossibility);
     *  2. a row lock on the account inside the transaction;
     *  3. a ledger pre-check — if a charge row for this key already exists, return
     *     it and do NOT debit again;
     *  4. (the client_request_id collapse is the widget's job, Phase 7.)
     *
     * @param  array<string,mixed>  $meta
     */
    public function charge(
        Account $account,
        int $chargeMicroUsd,
        int $actualCostMicroUsd,
        string $idempotencyKey,
        ?int $generationId = null,
        ?Reservation $reservation = null,
        array $meta = [],
        string $referenceType = CreditLedger::REFERENCE_GENERATION,
    ): CreditLedger {
        $charge = abs($chargeMicroUsd);

        $result = DB::transaction(function () use ($account, $charge, $actualCostMicroUsd, $idempotencyKey, $generationId, $meta, $referenceType) {
            /** @var Account $locked */
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            // LAYER 3: ledger pre-check. A charge for this key already exists ->
            // return it, never debit twice. The row lock above serializes same-account
            // writers, so the second one sees the committed row here. (LAYER 1 — the
            // unique index — is the cross-connection backstop, caught below.)
            $existing = $this->existingByKey($idempotencyKey);
            if ($existing !== null) {
                return ['row' => $existing, 'fresh' => false];
            }

            $balanceAfter = $locked->balance_micro_usd - $charge;

            try {
                $ledger = $this->insert(
                    account: $locked,
                    type: CreditLedger::TYPE_CHARGE,
                    amountMicroUsd: -$charge,
                    balanceAfter: $balanceAfter,
                    idempotencyKey: $idempotencyKey,
                    referenceType: $referenceType,
                    referenceId: $generationId,
                    actualCostMicroUsd: $actualCostMicroUsd,
                    meta: $meta,
                );
            } catch (QueryException $e) {
                // LAYER 1: the unique index rejected a racing duplicate. The other
                // writer already charged; return its row, do NOT debit again.
                return ['row' => $this->resolveDuplicateOrThrow($idempotencyKey, $e), 'fresh' => false];
            }

            $locked->forceFill([self::BALANCE_COLUMN => $balanceAfter])->save();
            $account->setAttribute(self::BALANCE_COLUMN, $balanceAfter);

            return ['row' => $ledger, 'fresh' => true];
        });

        $row = $result['row'];

        // Release the in-flight reservation once the charge is committed (debit on
        // success replaces the held estimate). Outside the txn; release is idempotent,
        // so a duplicate charge still safely releases without double-decrementing.
        if ($reservation !== null) {
            $this->reservations->release($reservation);
        }

        // Only trace a FRESH charge — a duplicate (already-charged) records nothing.
        if ($result['fresh']) {
            $this->activity->record(
                kind: ActivityEvent::KIND_CREDIT_CHARGED,
                subject: $account,
                details: [
                    'amount_micro_usd' => $row->amount_micro_usd,
                    'actual_cost_micro_usd' => $actualCostMicroUsd,
                    'balance_after_micro_usd' => $row->balance_after_micro_usd,
                    'generation_id' => $generationId,
                ],
            );
        }

        return $row;
    }

    /**
     * Reverse a charge. A new positive `refund` row (never an edit of the charge).
     * Idempotent on refund:{account}:{generation}.
     *
     * @param  array<string,mixed>  $meta
     */
    public function refund(
        Account $account,
        int $amountMicroUsd,
        string $idempotencyKey,
        ?int $generationId = null,
        array $meta = [],
    ): CreditLedger {
        $row = $this->append(
            account: $account,
            type: CreditLedger::TYPE_REFUND,
            amountMicroUsd: abs($amountMicroUsd),
            idempotencyKey: $idempotencyKey,
            referenceType: CreditLedger::REFERENCE_GENERATION,
            referenceId: $generationId,
            meta: $meta,
            activityKind: ActivityEvent::KIND_CREDIT_REFUNDED,
            subject: $account,
        );

        return $row;
    }

    /**
     * An admin adjustment, either sign. A new row; the idempotency key (a
     * deterministic admin slug) makes a re-run a no-op.
     *
     * @param  array<string,mixed>  $meta
     */
    public function adjustment(
        Account $account,
        int $amountMicroUsd,
        string $idempotencyKey,
        string $description = '',
        array $meta = [],
    ): CreditLedger {
        return $this->append(
            account: $account,
            type: CreditLedger::TYPE_ADJUSTMENT,
            amountMicroUsd: $amountMicroUsd, // signed: admin can add or subtract
            idempotencyKey: $idempotencyKey,
            meta: ['description' => $description] + $meta,
            activityKind: ActivityEvent::KIND_CREDIT_ADJUSTED,
            subject: $account,
        );
    }

    /**
     * The FAILURE path: release the reservation and write NO charge row. The
     * merchant is never billed for a failed try-on. Leaves an activity trace.
     *
     * @param  array<string,mixed>  $details
     */
    public function release(Reservation $reservation, array $details = []): void
    {
        $this->reservations->release($reservation);

        $account = Account::query()->find($reservation->accountId);

        if ($account !== null) {
            $this->activity->record(
                kind: ActivityEvent::KIND_CREDIT_RESERVATION_RELEASED,
                subject: $account,
                details: ['reservation_id' => $reservation->id, 'estimate_micro_usd' => $reservation->estimateMicroUsd] + $details,
            );
        }
    }

    /** Look up an existing ledger row by its idempotency key (the pre-check). */
    public function existingByKey(string $idempotencyKey): ?CreditLedger
    {
        return CreditLedger::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * True if a charge row exists for a reference (the money-path short-circuit). Defaults to
     * a generation reference so every existing caller is unchanged; the banner money path
     * passes REFERENCE_BANNER_ASSET so a banner asset's charge is looked up on its own row.
     */
    public function hasCharge(int $referenceId, string $referenceType = CreditLedger::REFERENCE_GENERATION): bool
    {
        return CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->exists();
    }

    /**
     * The shared positive/signed append: a single row, balance moved, all under a
     * row lock, idempotent on the key. Used by grant/purchase/refund/adjustment.
     * charge() is bespoke (it carries the reservation release + cost column).
     *
     * @param  array<string,mixed>  $meta
     */
    private function append(
        Account $account,
        string $type,
        int $amountMicroUsd,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
        string $activityKind = ActivityEvent::KIND_CREDIT_ADJUSTED,
        ?\Illuminate\Database\Eloquent\Model $subject = null,
    ): CreditLedger {
        $row = DB::transaction(function () use ($account, $type, $amountMicroUsd, $idempotencyKey, $referenceType, $referenceId, $meta) {
            /** @var Account $locked */
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            // Idempotency pre-check: a row for this key already exists -> return it.
            $existing = $this->existingByKey($idempotencyKey);
            if ($existing !== null) {
                return ['row' => $existing, 'fresh' => false];
            }

            $balanceAfter = $locked->balance_micro_usd + $amountMicroUsd;

            try {
                $ledger = $this->insert(
                    account: $locked,
                    type: $type,
                    amountMicroUsd: $amountMicroUsd,
                    balanceAfter: $balanceAfter,
                    idempotencyKey: $idempotencyKey,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    actualCostMicroUsd: null,
                    meta: $meta,
                );
            } catch (QueryException $e) {
                // The unique index rejected a racing duplicate; return the winner's row.
                return ['row' => $this->resolveDuplicateOrThrow($idempotencyKey, $e), 'fresh' => false];
            }

            $locked->forceFill([self::BALANCE_COLUMN => $balanceAfter])->save();
            $account->setAttribute(self::BALANCE_COLUMN, $balanceAfter);

            return ['row' => $ledger, 'fresh' => true];
        });

        // Only trace a FRESH row — a no-op (idempotent re-run) records nothing.
        if ($row['fresh']) {
            $this->activity->record(
                kind: $activityKind,
                subject: $subject,
                details: [
                    'type' => $row['row']->type,
                    'amount_micro_usd' => $row['row']->amount_micro_usd,
                    'balance_after_micro_usd' => $row['row']->balance_after_micro_usd,
                ],
            );
        }

        return $row['row'];
    }

    /**
     * The raw insert of one ledger row. account_id is stamped by BelongsToAccount
     * from the bound tenant; everything else is set here by the writer.
     *
     * @param  array<string,mixed>  $meta
     */
    private function insert(
        Account $account,
        string $type,
        int $amountMicroUsd,
        int $balanceAfter,
        string $idempotencyKey,
        ?string $referenceType,
        ?int $referenceId,
        ?int $actualCostMicroUsd,
        array $meta,
    ): CreditLedger {
        $ledger = new CreditLedger;
        $ledger->account_id = $account->getKey();
        $ledger->type = $type;
        $ledger->amount_micro_usd = $amountMicroUsd;
        $ledger->balance_after_micro_usd = $balanceAfter;
        $ledger->idempotency_key = $idempotencyKey;
        $ledger->reference_type = $referenceType;
        $ledger->reference_id = $referenceId;
        $ledger->actual_cost_micro_usd = $actualCostMicroUsd;
        $ledger->meta = $meta;
        $ledger->created_at = now();
        $ledger->save();

        return $ledger;
    }

    /** @return Builder<CreditLedger> a per-account ledger query (timeline reads). */
    public function for(Account $account): Builder
    {
        return CreditLedger::query()->where('account_id', $account->getKey());
    }

    /**
     * A write hit the unique idempotency_key index (a racing duplicate). The other
     * writer already committed; return ITS row so the duplicate is a no-op. If no
     * row is found (the QueryException was something else), re-throw — never swallow
     * an unrelated DB error on the money path.
     */
    private function resolveDuplicateOrThrow(string $idempotencyKey, QueryException $e): CreditLedger
    {
        $existing = $this->existingByKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        throw $e;
    }
}
