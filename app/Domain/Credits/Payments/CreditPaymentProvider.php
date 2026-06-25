<?php

namespace App\Domain\Credits\Payments;

use App\Models\Account;
use Illuminate\Http\Request;

/**
 * CreditPaymentProvider — the swappable credit-purchase rail (ARCHITECTURE.md
 * "Credit purchase rail"). v1 is LOCKED to PayPlus, but every call site depends on
 * THIS interface, never on PayPlus directly — so a future Stripe rail is a binding
 * swap with zero call-site change.
 *
 * Two responsibilities, both server-side (the OpenRouter / provider keys never reach
 * the browser):
 *  1. initiatePurchase — create a hosted payment page for a top-up and return a
 *     PurchaseIntent (a redirect URL + the provider's reference). Nothing is credited
 *     here; this is the OUTBOUND half.
 *  2. verifyAndParseWebhook — VERIFY the provider's webhook signature, then parse it
 *     into a PurchaseResult (provider_ref, provider-confirmed amount, status). Returns
 *     NULL for a forged/unsigned/unparseable webhook (the controller no-ops it). This
 *     is the source of truth for `paid`; the controller turns one verified PAID result
 *     into exactly one `purchase` ledger row, idempotently.
 *
 * The provider NEVER touches the ledger or the credit_purchases table — it only talks
 * to the external rail and verifies signatures. The purchase flow + the webhook
 * controller own persistence and the (single, idempotent) ledger write.
 */
interface CreditPaymentProvider
{
    /** The provider identifier used in credit_purchases.provider + the idempotency key. */
    public function name(): string;

    /**
     * Create a hosted payment page for a $amountMicroUsd top-up for $account and
     * return a PurchaseIntent (redirect URL + provider_ref). A top-up buys credits at
     * FACE VALUE — the 2.5x markup applies on spend, not on purchase — so the caller
     * grants amountMicroUsd selling-value credits when the webhook confirms payment.
     *
     * @param  array<string,mixed>  $context  return/callback URLs, locale, etc.
     */
    public function initiatePurchase(Account $account, int $amountMicroUsd, array $context = []): PurchaseIntent;

    /**
     * Verify the provider's webhook SIGNATURE and parse it. Returns a PurchaseResult on
     * success, or NULL when the request is not a verifiable webhook for us (forged,
     * unsigned, wrong shape) — the controller acks a null with 200 and credits nothing.
     *
     * The returned amount is the PROVIDER-CONFIRMED amount from the signed body, never a
     * client figure. The account is NOT taken from the body; the controller resolves it
     * from our credit_purchases row keyed by provider_ref.
     */
    public function verifyAndParseWebhook(Request $request): ?PurchaseResult;
}
