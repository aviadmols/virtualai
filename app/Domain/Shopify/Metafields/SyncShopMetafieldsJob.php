<?php

namespace App\Domain\Shopify\Metafields;

use App\Domain\Shopify\Api\ShopifyApiException;
use App\Domain\Shopify\Api\ShopifyGraphQLClient;
use App\Jobs\TenantAwareJob;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;

/**
 * SyncShopMetafieldsJob — write the site's PUBLIC site_key to an APP-OWNED shop metafield
 * ($app:settings/site_key) so the Theme App Extension reads it automatically and the merchant
 * never pastes the key into the theme editor by hand. Only the PUBLIC key is written — never
 * the widget_secret, never a token.
 *
 * CONVERGENT + IDEMPOTENT: the connection remembers the last key it synced
 * (metafields_synced_key); when it already equals the site's current key the job makes ZERO
 * API calls. Dispatched on install/re-auth (ShopifyInstaller::connect), on site-key rotation
 * (SiteKeyRegenerator), and self-heals on embedded-app open (EmbeddedAppApiController) for
 * stores installed before this job existed.
 *
 * Tenant-safe: explicit account_id (TenantAwareJob). Failure is logged and left for the next
 * trigger — the theme block still accepts a manually-pasted key as the override/fallback.
 */
final class SyncShopMetafieldsJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const IDEMPOTENCY_PREFIX = 'shopify-metafields';

    private const CFG_QUEUE = 'trayon.queues.webhooks';

    // The app-reserved namespace + key the theme extension reads:
    // Liquid: {{ app.metafields.settings.site_key.value }}
    public const NAMESPACE = '$app:settings';

    public const KEY_SITE_KEY = 'site_key';

    private const TYPE_TEXT = 'single_line_text_field';

    private const QUERY_SHOP_ID = <<<'GRAPHQL'
    query vsioShopId { shop { id } }
    GRAPHQL;

    private const MUTATION = <<<'GRAPHQL'
    mutation vsioShopMetafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id }
        userErrors { field message }
      }
    }
    GRAPHQL;

    private const VAR_METAFIELDS = 'metafields';

    private const RESULT_ROOT = 'metafieldsSet';

    private const RESULT_USER_ERRORS = 'userErrors';

    private const LOG_SYNCED = 'shopify.metafields.synced';

    private const LOG_FAILED = 'shopify.metafields.sync_failed';

    private const LOG_SKIPPED = 'shopify.metafields.skipped';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    /**
     * Unique per (account, site) AND per key VALUE: a rotation dispatched while an older sync
     * is still in flight must NOT be swallowed, or the shop would keep the invalidated key.
     * uniqueId() runs at DISPATCH time, which may be outside any tenant bind — bind the job's
     * own explicit account so the fail-closed scope resolves the site.
     */
    public function uniqueId(): string
    {
        $key = (string) Tenant::run($this->accountId, fn (): ?string => Site::query()->whereKey($this->siteId)->value('site_key'));

        return self::IDEMPOTENCY_PREFIX.':'.$this->accountId.':'.$this->siteId.':'.$key;
    }

    protected function process(): void
    {
        // Fail-closed reads: another account's site id simply does not resolve here.
        $connection = ShopifyConnection::query()->where('site_id', $this->siteId)->first();
        $site = Site::query()->find($this->siteId);

        if ($connection === null || $site === null || ! $connection->isInstalled()) {
            Log::info(self::LOG_SKIPPED, ['account_id' => $this->accountId, 'site_id' => $this->siteId]);

            return;
        }

        $siteKey = (string) $site->site_key;

        // Converged: this exact key is already on the shop — zero API calls.
        if ($siteKey === '' || $connection->metafields_synced_key === $siteKey) {
            return;
        }

        $context = [
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'shop_domain' => $connection->shop_domain,
        ];

        try {
            $client = app(ShopifyGraphQLClient::class);

            $shopId = (string) ($client->query($connection, self::QUERY_SHOP_ID)['shop']['id'] ?? '');

            if ($shopId === '') {
                Log::warning(self::LOG_FAILED, $context + ['reason' => 'no_shop_id']);

                return;
            }

            $data = $client->query($connection, self::MUTATION, [
                self::VAR_METAFIELDS => [[
                    'ownerId' => $shopId,
                    'namespace' => self::NAMESPACE,
                    'key' => self::KEY_SITE_KEY,
                    'type' => self::TYPE_TEXT,
                    'value' => $siteKey,
                ]],
            ]);
        } catch (ShopifyApiException $e) {
            Log::warning(self::LOG_FAILED, $context + ['code' => $e->errorCode]);

            return; // the next trigger (install / rotation / app open) retries
        }

        $errors = (array) ($data[self::RESULT_ROOT][self::RESULT_USER_ERRORS] ?? []);

        if ($errors !== []) {
            Log::warning(self::LOG_FAILED, $context + ['user_error' => (string) ($errors[0]['message'] ?? '')]);

            return;
        }

        $connection->forceFill(['metafields_synced_key' => $siteKey])->save();

        Log::info(self::LOG_SYNCED, $context);
    }
}
