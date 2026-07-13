<?php

namespace App\Http\Shopify\Controllers;

use App\Domain\Shopify\ShopifyLogContext;
use App\Domain\Shopify\Webhooks\ShopifyWebhookDispatcher;
use App\Domain\Shopify\Webhooks\ShopifyWebhookVerifier;
use App\Models\ShopifyWebhookReceipt;
use App\Support\CorrelationId;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController — the single Shopify webhook intake (every topic, including the
 * mandatory GDPR ones).
 *
 * The contract Shopify enforces: answer within 5 SECONDS or the delivery is a failure
 * (and repeated failures unsubscribe the app). So this endpoint does NO work: verify,
 * receipt, dispatch, 200.
 *
 *  1. VERIFY the raw-body HMAC (fail closed -> 401, and NO receipt row is written: an
 *     unsigned POST must not be able to fill our inbox).
 *  2. DEDUPE on X-Shopify-Webhook-Id (the unique index is the wall). A replay answers
 *     200 without re-dispatching — at-most-once processing.
 *  3. RECEIPT (status=received, correlation_id minted at this edge and carried through
 *     every downstream log line and job).
 *  4. DISPATCH through ShopifyWebhookDispatcher: it resolves the owning account with the
 *     PRE-BIND router and queues the topic handler with an EXPLICIT account_id. An
 *     unknown shop / unmapped topic fails the RECEIPT loudly but still answers 200 —
 *     Shopify must not retry something we durably recorded.
 *
 * No CSRF, no session: the signature is the auth.
 */
final class WebhookController
{
    // === CONSTANTS ===
    private const LOG_RECEIVED = 'shopify.webhook.received';

    private const LOG_DUPLICATE = 'shopify.webhook.duplicate';

    private const LOG_REJECTED = 'shopify.webhook.rejected';

    private const STATUS_UNAUTHORIZED = 401;

    // Response keys (Shopify ignores the body; these are for our own tooling/tests).
    private const R_OK = 'ok';

    private const R_DUPLICATE = 'duplicate';

    private const R_ERROR = 'error';

    private const ERROR_INVALID_SIGNATURE = 'invalid_signature';

    public function __construct(
        private readonly ShopifyWebhookVerifier $verifier,
        private readonly ShopifyWebhookDispatcher $dispatcher,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 1. FAIL CLOSED on an unverifiable delivery. Nothing is persisted.
        if (! $this->verifier->verify($request)) {
            Log::warning(self::LOG_REJECTED, ['shop_domain' => $request->header(ShopifyWebhookVerifier::HEADER_SHOP_DOMAIN)]);

            return response()->json([self::R_OK => false, self::R_ERROR => self::ERROR_INVALID_SIGNATURE], self::STATUS_UNAUTHORIZED);
        }

        $webhookId = (string) $request->header(ShopifyWebhookVerifier::HEADER_WEBHOOK_ID, '');
        $topic = (string) $request->header(ShopifyWebhookVerifier::HEADER_TOPIC, '');
        $shopDomain = (string) $request->header(ShopifyWebhookVerifier::HEADER_SHOP_DOMAIN, '');

        // A signed delivery with no delivery id cannot be deduped; mint one from the body
        // so the inbox stays at-most-once instead of silently re-processing.
        if ($webhookId === '') {
            $webhookId = sha1($shopDomain.$topic.$request->getContent());
        }

        // 2. DEDUPE: a replayed delivery is acknowledged, never re-dispatched.
        $existing = ShopifyWebhookReceipt::query()->where('webhook_id', $webhookId)->first();

        if ($existing !== null) {
            Log::info(self::LOG_DUPLICATE, ShopifyLogContext::receipt($existing));

            return response()->json([self::R_OK => true, self::R_DUPLICATE => true]);
        }

        // 3. RECEIPT the delivery durably before any dispatch.
        try {
            $receipt = ShopifyWebhookReceipt::create([
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'status' => ShopifyWebhookReceipt::STATUS_RECEIVED,
                'payload' => (array) $request->json()->all(),
                'attempts' => 0,
                'correlation_id' => CorrelationId::mint(),
            ]);
        } catch (QueryException) {
            // The unique webhook_id index fired: two deliveries raced. The other one owns
            // the dispatch — acknowledge and do nothing (still at-most-once).
            Log::info(self::LOG_DUPLICATE, ['webhook_id' => $webhookId, 'topic' => $topic, 'shop_domain' => $shopDomain]);

            return response()->json([self::R_OK => true, self::R_DUPLICATE => true]);
        }

        Log::info(self::LOG_RECEIVED, ShopifyLogContext::receipt($receipt));

        // 4. DISPATCH (explicit account_id, resolved pre-bind). A failed receipt is a
        // durable, replayable record — Shopify still gets its 200.
        $this->dispatcher->dispatch($receipt);

        return response()->json([self::R_OK => true, self::R_DUPLICATE => false]);
    }
}
