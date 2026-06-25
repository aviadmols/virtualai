<?php

namespace App\Http\Controllers\Credits;

use App\Domain\Credits\Payments\CreditProviderResolver;
use App\Domain\Credits\Payments\PurchaseReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PurchaseWebhookController — the IDEMPOTENT credit-purchase webhook (the source of
 * truth for `paid`). The provider POSTs here after the merchant pays the hosted page.
 *
 * Flow (every step fail-closed):
 *  1. resolve the provider for this route ({provider} segment), verify the SIGNATURE
 *     and parse the body through it. A forged/unsigned/unparseable webhook -> null ->
 *     we ack 200 and credit nothing (the provider stops retrying; nothing leaked).
 *  2. hand the verified PurchaseResult to the reconciler, which idempotently writes AT
 *     MOST ONE `purchase` ledger row (a replayed webhook is a no-op via the ledger_id
 *     wall). The account is resolved from OUR row, never trusted from the body.
 *  3. return 200 only AFTER safe handling so the provider does not retry a handled call.
 *
 * The OpenRouter/provider secrets never reach the browser; this route is server-to-server
 * only. No CSRF (it is an external POST); signature verification is the auth.
 */
final class PurchaseWebhookController
{
    public function __construct(
        private readonly CreditProviderResolver $resolver,
        private readonly PurchaseReconciler $reconciler,
    ) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $paymentProvider = $this->resolver->byName($provider);

        $result = $paymentProvider->verifyAndParseWebhook($request);

        // Not a verifiable webhook for us (forged / unsigned / wrong shape). Ack so the
        // provider stops retrying; credit NOTHING.
        if ($result === null) {
            return response()->json(['ok' => true, 'credited' => false]);
        }

        $purchase = $this->reconciler->reconcile($result);

        if ($purchase === null) {
            // Verified signature but no matching purchase row for the ref. Ack; no credit.
            Log::warning('credits.purchase_webhook.unknown_ref', [
                'provider' => $result->provider,
            ]);

            return response()->json(['ok' => true, 'credited' => false]);
        }

        return response()->json([
            'ok' => true,
            'credited' => $purchase->isCredited(),
            'status' => $purchase->status,
        ]);
    }
}
