<?php

namespace App\Domain\Credits\Payments;

use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\CreditPurchase;
use App\Support\Tenant;
use Illuminate\Support\Str;

/**
 * PurchaseInitiator — the OUTBOUND half of the purchase rail. A merchant tops up:
 *  1. mint a per-attempt provider_ref (so each top-up is its own page);
 *  2. ask the resolved provider to create a hosted payment page;
 *  3. persist a `pending` credit_purchases row keyed by the deterministic idempotency
 *     key purchase:{account}:{provider}:{provider_ref};
 *  4. return the redirect URL for the merchant to pay.
 *
 * NOTHING is credited here — the credit_ledger `purchase` row is only written later by
 * the idempotent webhook on a confirmed `paid`. The pending row is the OUR-SIDE record
 * the webhook resolves the account from (the account is never trusted from the webhook
 * body). credit_purchases (platform revenue) stays separate from credit_ledger.
 *
 * A top-up buys credits at FACE VALUE: credits_micro_usd == amountMicroUsd. The 2.5x
 * markup is earned on SPEND (a generation), never on the purchase.
 */
final class PurchaseInitiator
{
    public function __construct(
        private readonly CreditProviderResolver $resolver,
    ) {}

    /**
     * Start a top-up of $amountMicroUsd for $account. Returns the PurchaseIntent (the
     * redirect URL). Must be called with $account bound as the tenant so the pending
     * credit_purchases row stamps account_id under BelongsToAccount.
     *
     * @param  array<string,mixed>  $context  success/failure/cancel + callback URLs
     */
    public function initiate(Account $account, int $amountMicroUsd, array $context = []): PurchaseIntent
    {
        $provider = $this->resolver->for($account);
        $providerRef = $this->mintProviderRef();
        $idempotencyKey = IdempotencyKey::forPurchase($account->getKey(), $provider->name(), $providerRef);

        $intent = $provider->initiatePurchase($account, $amountMicroUsd, [
            'provider_ref' => $providerRef,
        ] + $context);

        if (! $intent->ok) {
            // Provider refused / unreachable — persist nothing; the merchant retries.
            return $intent;
        }

        Tenant::run($account, function () use ($account, $provider, $providerRef, $amountMicroUsd, $idempotencyKey): void {
            CreditPurchase::query()->create([
                'provider' => $provider->name(),
                'provider_ref' => $providerRef,
                'amount_usd' => round($amountMicroUsd / 1_000_000, 2),
                // Face value: a top-up grants amountMicroUsd selling-value credits.
                'credits_micro_usd' => $amountMicroUsd,
                'status' => CreditPurchase::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        return $intent;
    }

    /** A per-attempt provider reference (each top-up is its own payment page). */
    private function mintProviderRef(): string
    {
        return 'trayon_'.Str::ulid()->toBase32();
    }
}
