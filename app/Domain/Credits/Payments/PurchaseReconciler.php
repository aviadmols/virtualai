<?php

namespace App\Domain\Credits\Payments;

use App\Domain\Credits\CreditLedgerService;
use App\Models\CreditPurchase;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * PurchaseReconciler — the IDEMPOTENT inbound half of the purchase rail. Given a
 * signature-VERIFIED PurchaseResult (the controller already verified + parsed it), it
 * turns exactly one `paid` event into exactly one `purchase` credit_ledger row.
 *
 * The dedupe wall (a replayed/retried webhook credits AT MOST ONCE):
 *  1. resolve the credit_purchases row by provider_ref WITHOUT a tenant bound, then
 *     bind the OWNING account from THAT row — the account is never trusted from the
 *     webhook body (a webhook can never credit an account it wasn't initiated for);
 *  2. lockForUpdate the purchase row inside the transaction (serialises retries);
 *  3. if ledger_id is already set -> ALREADY CREDITED -> no-op (the wall);
 *  4. a non-paid status updates the row's status and writes NO ledger row;
 *  5. on paid -> CreditLedgerService::purchase() with the deterministic key
 *     (purchase:{account}:{provider}:{provider_ref}) writes ONE ledger row; the
 *     purchase.ledger_id is set in the SAME transaction (the 1:1 link).
 *
 * The credit_ledger writer is itself idempotent on that key, so even a cross-connection
 * race collapses to one row. credit_purchases (platform revenue) and credit_ledger
 * (merchant spend) stay separate, linked only by ledger_id.
 */
final class PurchaseReconciler
{
    public function __construct(
        private readonly CreditLedgerService $ledger,
        private readonly PurchaseRouter $router,
    ) {}

    /**
     * Reconcile a verified webhook result. Returns the (created or pre-existing)
     * credit_purchases row, or null when no matching purchase exists for the ref.
     * Idempotent: a second delivery of the same paid ref is a no-op.
     */
    public function reconcile(PurchaseResult $result): ?CreditPurchase
    {
        // Resolve the OUR-SIDE row by provider_ref, UNSCOPED by tenant (the webhook has
        // no bound tenant). withoutGlobalScopes is NOT used; instead we look up the row
        // with the account_id deliberately not yet bound, via the audited resolver below.
        $purchase = $this->findByProviderRef($result->provider, $result->providerRef);

        if ($purchase === null) {
            // No checkout row for this ref. Defensive: a provider could deliver before
            // our pending insert committed, but our flow always persists first, so an
            // unknown ref is treated as not-ours (no row created from the body — the
            // account is unknown and we never trust the body for it).
            return null;
        }

        $account = $purchase->account; // the owning account, from OUR row

        return Tenant::run($account, function () use ($purchase, $result, $account): CreditPurchase {
            return DB::transaction(function () use ($purchase, $result, $account): CreditPurchase {
                /** @var CreditPurchase $locked */
                $locked = CreditPurchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

                // THE WALL: already credited -> idempotent no-op (a retried webhook).
                // NOTE (refund policy, tracked for a later phase / TS-CREDITS-005): a
                // REFUNDED webhook that arrives AFTER a paid purchase hits this wall and is
                // a no-op — we do NOT auto-claw the granted credits here. A refund of
                // already-spent credits is a deliberate adjustment/refund ledger decision,
                // not a silent clawback; surface it for manual handling, do not auto-reverse.
                if ($locked->ledger_id !== null) {
                    return $locked;
                }

                // A non-paid result records the provider's status and credits nothing.
                if (! $result->isPaid()) {
                    $locked->update(['status' => $this->mapNonPaidStatus($result->status)]);

                    return $locked;
                }

                // DEFENSE IN DEPTH: assert the provider-confirmed amount matches what we
                // recorded on initiate. We already never trust the client body for the
                // credit amount (we grant the persisted face value), but a verified webhook
                // whose amount differs from our intent is an anomaly (tampered page, config
                // drift, a mis-routed ref). Park it as amount_mismatch, write NO ledger row,
                // and leave it for manual review — never silently credit on a mismatch.
                if ($result->amountMicroUsd !== $locked->credits_micro_usd) {
                    $locked->update(['status' => CreditPurchase::STATUS_AMOUNT_MISMATCH]);

                    return $locked;
                }

                // PAID + amount matches: write EXACTLY ONE `purchase` ledger row through the
                // ledger writer (never a bare balance write, never a second table) at the
                // FACE VALUE — markup is on spend, not on purchase.
                $ledgerRow = $this->ledger->purchase(
                    account: $account,
                    amountMicroUsd: $locked->credits_micro_usd,
                    idempotencyKey: $locked->idempotency_key,
                    description: $this->describe($locked),
                    meta: [
                        'source' => 'credit_purchase',
                        'provider' => $locked->provider,
                        'provider_ref' => $locked->provider_ref,
                        'credit_purchase_id' => $locked->getKey(),
                        'provider_confirmed_micro_usd' => $result->amountMicroUsd,
                    ],
                );

                // Link 1:1 in the SAME transaction; mark paid.
                $locked->update([
                    'status' => CreditPurchase::STATUS_PAID,
                    'ledger_id' => $ledgerRow->getKey(),
                    'paid_at' => now(),
                ]);

                return $locked;
            });
        });
    }

    /**
     * Resolve the pending purchase row by provider + ref. credit_purchases is
     * BelongsToAccount (fail-closed when unbound), and a webhook has no bound tenant, so
     * we FIRST route to the owning account (PurchaseRouter returns ONLY the account_id),
     * THEN bind that tenant and read the full row through the NORMAL global scope. The
     * data read is always tenant-scoped; only the integer routing fact crosses the scope.
     */
    private function findByProviderRef(string $provider, string $providerRef): ?CreditPurchase
    {
        $accountId = $this->router->accountIdForRef($provider, $providerRef);

        if ($accountId === null) {
            return null;
        }

        return Tenant::run($accountId, fn () => CreditPurchase::query()
            ->where('provider', $provider)
            ->where('provider_ref', $providerRef)
            ->first());
    }

    private function mapNonPaidStatus(string $resultStatus): string
    {
        return match ($resultStatus) {
            PurchaseResult::STATUS_REFUNDED => CreditPurchase::STATUS_REFUNDED,
            default => CreditPurchase::STATUS_FAILED,
        };
    }

    private function describe(CreditPurchase $purchase): string
    {
        return sprintf('Credit top-up %s %s', number_format((float) $purchase->amount_usd, 2), $purchase->currency);
    }
}
