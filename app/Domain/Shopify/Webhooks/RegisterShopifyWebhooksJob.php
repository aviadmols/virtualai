<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\Api\ShopifyApiException;
use App\Domain\Shopify\Api\ShopifyGraphQLClient;
use App\Jobs\TenantAwareJob;
use App\Models\ShopifyConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;

/**
 * RegisterShopifyWebhooksJob — subscribe the freshly-connected store to every topic in
 * config('shopify.topics'), and remember topic => subscription id on the connection.
 *
 * IDEMPOTENT BY CONSTRUCTION (a re-install / a retry / a double-click must never create
 * duplicate subscriptions):
 *  - the job is ShouldBeUnique per (account, site);
 *  - a topic already present in the connection's webhook_registration map is SKIPPED —
 *    re-running the job makes zero API calls once every topic is registered;
 *  - Shopify's own "address for this topic has already been taken" userError (our map
 *    was lost, the subscription exists) is recorded as registered, not retried forever.
 *
 * Tenant-safe: an explicit account_id (TenantAwareJob binds it and clears in finally).
 * GDPR compliance topics are registered from the Partner Dashboard app config, not here.
 */
final class RegisterShopifyWebhooksJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const IDEMPOTENCY_PREFIX = 'shopify-webhooks';

    private const CFG_TOPICS = 'shopify.topics';

    private const CFG_QUEUE = 'trayon.queues.webhooks';

    // The webhook intake route the subscriptions point at (absolute HTTPS URL).
    private const ROUTE_WEBHOOKS = 'shopify.webhooks';

    private const MUTATION = <<<'GRAPHQL'
    mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
      webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
        webhookSubscription { id }
        userErrors { field message }
      }
    }
    GRAPHQL;

    private const VAR_TOPIC = 'topic';

    private const VAR_SUBSCRIPTION = 'webhookSubscription';

    private const INPUT_CALLBACK_URL = 'callbackUrl';

    private const INPUT_FORMAT = 'format';

    private const FORMAT_JSON = 'JSON';

    private const RESULT_ROOT = 'webhookSubscriptionCreate';

    private const RESULT_SUBSCRIPTION = 'webhookSubscription';

    private const RESULT_ID = 'id';

    private const RESULT_USER_ERRORS = 'userErrors';

    // Shopify's "you already have a subscription for this topic+address" userError.
    private const ERROR_ALREADY_TAKEN = 'already been taken';

    // The value stored when the subscription exists on Shopify but its id is unknown to
    // us (map lost): registered, so we stop calling — Phase 3 can reconcile real ids.
    public const REGISTRATION_EXISTING = 'existing';

    private const LOG_REGISTERED = 'shopify.webhooks.registered';

    private const LOG_FAILED = 'shopify.webhooks.register_failed';

    private const LOG_SKIPPED = 'shopify.webhooks.skipped';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
    ) {
        parent::__construct($accountId);
        // Read the queue from config (a config:cache'd app has no Q_* constants at runtime).
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    public function uniqueId(): string
    {
        return self::IDEMPOTENCY_PREFIX.':'.$this->accountId.':'.$this->siteId;
    }

    protected function process(): void
    {
        // Fail-closed read: another account's site id simply does not resolve here.
        $connection = ShopifyConnection::query()
            ->where('site_id', $this->siteId)
            ->first();

        if ($connection === null || ! $connection->isInstalled()) {
            Log::info(self::LOG_SKIPPED, ['account_id' => $this->accountId, 'site_id' => $this->siteId]);

            return;
        }

        $client = app(ShopifyGraphQLClient::class);
        $registration = (array) ($connection->webhook_registration ?? []);
        $callbackUrl = route(self::ROUTE_WEBHOOKS);

        foreach ((array) config(self::CFG_TOPICS) as $topic) {
            $topic = (string) $topic;

            // Already registered by an earlier run: no API call at all (the idempotency wall).
            if (isset($registration[$topic])) {
                continue;
            }

            $id = $this->subscribe($client, $connection, $topic, $callbackUrl);

            if ($id !== null) {
                $registration[$topic] = $id;
            }
        }

        $connection->webhook_registration = $registration;
        $connection->save();
    }

    /** One webhookSubscriptionCreate call. Returns the subscription id, or null on failure. */
    private function subscribe(ShopifyGraphQLClient $client, ShopifyConnection $connection, string $topic, string $callbackUrl): ?string
    {
        $context = [
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'shop_domain' => $connection->shop_domain,
            'topic' => $topic,
        ];

        try {
            $data = $client->query($connection, self::MUTATION, [
                self::VAR_TOPIC => self::topicEnum($topic),
                self::VAR_SUBSCRIPTION => [
                    self::INPUT_CALLBACK_URL => $callbackUrl,
                    self::INPUT_FORMAT => self::FORMAT_JSON,
                ],
            ]);
        } catch (ShopifyApiException $e) {
            Log::warning(self::LOG_FAILED, $context + ['code' => $e->errorCode]);

            return null; // the next run retries this topic; nothing is half-recorded
        }

        $result = (array) ($data[self::RESULT_ROOT] ?? []);
        $id = $result[self::RESULT_SUBSCRIPTION][self::RESULT_ID] ?? null;

        if (is_string($id) && $id !== '') {
            Log::info(self::LOG_REGISTERED, $context + ['subscription_id' => $id]);

            return $id;
        }

        $errors = (array) ($result[self::RESULT_USER_ERRORS] ?? []);
        $message = (string) ($errors[0]['message'] ?? '');

        // The subscription already exists on Shopify's side — treat it as registered so
        // the job converges instead of re-calling on every install/retry.
        if (str_contains(strtolower($message), self::ERROR_ALREADY_TAKEN)) {
            return self::REGISTRATION_EXISTING;
        }

        Log::warning(self::LOG_FAILED, $context + ['user_error' => $message]);

        return null;
    }

    /** 'products/update' -> the PRODUCTS_UPDATE WebhookSubscriptionTopic enum value. */
    private static function topicEnum(string $topic): string
    {
        return strtoupper(str_replace('/', '_', $topic));
    }
}
