<?php

namespace App\Domain\Shopify\Auth;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Shopify\Metafields\SyncShopMetafieldsJob;
use App\Domain\Shopify\Webhooks\RegisterShopifyWebhooksJob;
use App\Http\Shopify\ShopifyShopRouter;
use App\Models\ActivityEvent;
use App\Models\ShopifyConnection;
use App\Models\ShopifyPendingInstall;
use App\Models\Site;
use App\Support\CorrelationId;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ShopifyInstaller — the ONE place a ShopifyConnection is persisted.
 *
 * Every install origin (connect_existing_site, install_new_shop, re-install) funnels
 * through connect(): always inside Tenant::run($accountId) so the row is stamped by the
 * fail-closed BelongsToAccount scope, always UPSERTING by shop_domain (a re-install
 * RE-ACTIVATES the existing row via the guarded transitionTo — a shop_domain can never
 * duplicate), always writing the token through the EncryptedJson credentials cast,
 * always flipping sites.platform to 'shopify', and always dispatching webhook
 * registration with the EXPLICIT account_id.
 *
 * Cross-account theft is impossible: before any write, the PRE-BIND ShopifyShopRouter
 * (integer-only routing lookup) is asked who owns the shop_domain — a shop owned by
 * account A can never be attached to account B (typed 403, not a unique-index 500).
 *
 * No token, no secret, is ever logged: log lines carry shop_domain + account_id + the
 * correlation id only.
 */
final class ShopifyInstaller
{
    // === CONSTANTS ===
    private const LOG_INSTALLED = 'shopify.install.connected';

    private const LOG_PARKED = 'shopify.install.parked';

    private const LOG_CLAIMED = 'shopify.install.claimed';

    private const LOG_DISCONNECTED = 'shopify.install.disconnected';

    // Activity details recorded on the connection's timeline entry.
    private const DETAIL_FLOW = 'flow';

    private const DETAIL_CORRELATION_ID = 'correlation_id';

    private const DETAIL_SHOP_DOMAIN = 'shop_domain';

    // The flow recorded when the merchant disconnects from the panel.
    private const FLOW_MERCHANT_DISCONNECT = 'merchant_disconnect';

    public function __construct(
        private readonly ShopifyShopRouter $router,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Persist (or re-activate) the connection between $siteId and $shopDomain.
     *
     * @param  string  $flow  ShopifyOAuthState::FLOW_* — recorded on the timeline.
     */
    public function connect(
        int $accountId,
        int $siteId,
        string $shopDomain,
        ShopifyAccessToken $token,
        string $flow,
        ?string $correlationId = null,
    ): ShopifyConnection {
        $this->assertShopIsClaimableBy($accountId, $shopDomain);

        $correlationId ??= CorrelationId::mint();

        $connection = Tenant::run($accountId, fn (): ShopifyConnection => DB::transaction(function () use ($siteId, $shopDomain, $token, $flow, $correlationId): ShopifyConnection {
            // Fail-closed read: an id from another account simply does not exist here.
            // A missing/foreign site is the TYPED 403, never a ModelNotFound 500.
            $site = Site::query()->find($siteId);

            if ($site === null) {
                throw ShopifyOAuthException::siteNotOwned();
            }

            $existing = ShopifyConnection::query()
                ->where('shop_domain', $shopDomain)
                ->first();

            // The site already carries a DIFFERENT store — a typed conflict, never a
            // silent re-point (one Shopify store = one Site).
            $onSite = ShopifyConnection::query()->where('site_id', $site->getKey())->first();

            if ($onSite !== null && $onSite->shop_domain !== $shopDomain) {
                throw ShopifyOAuthException::siteAlreadyConnected($shopDomain);
            }

            $connection = $existing ?? new ShopifyConnection(['site_id' => $site->getKey(), 'shop_domain' => $shopDomain]);
            $connection->site_id = $site->getKey();
            $connection->shop_domain = $shopDomain;
            $connection->credentials = $token->toCredentials();
            $connection->needs_reauth = false;

            // A brand-new row is born installed; an existing UNINSTALLED row is
            // RE-ACTIVATED through the guarded state machine (never a duplicate).
            if (! $connection->exists) {
                $connection->status = ShopifyConnection::STATUS_INSTALLED;
                $connection->installed_at = now();
                $connection->save();

                // A fresh row has no transition to guard; the timeline entry is written
                // here so an install ALWAYS leaves the same trace as a re-activation.
                $this->activity->record(
                    kind: ActivityEvent::KIND_SHOPIFY_INSTALLED,
                    subject: $connection,
                    details: [
                        self::DETAIL_FLOW => $flow,
                        self::DETAIL_CORRELATION_ID => $correlationId,
                        self::DETAIL_SHOP_DOMAIN => $shopDomain,
                    ],
                    siteId: (int) $site->getKey(),
                );
            } elseif ($connection->status === ShopifyConnection::STATUS_UNINSTALLED) {
                $connection->save(); // persist the refreshed credentials first
                $connection->transitionTo(ShopifyConnection::STATUS_INSTALLED, [
                    self::DETAIL_FLOW => $flow,
                    self::DETAIL_CORRELATION_ID => $correlationId,
                ]);
            } else {
                $connection->installed_at = now();
                $connection->save(); // already installed: a token refresh (scopes changed)
            }

            $site->platform = Site::PLATFORM_SHOPIFY;
            $this->allowShopOrigin($site, $shopDomain);
            $site->save();

            return $connection;
        }));

        RegisterShopifyWebhooksJob::dispatch($accountId, (int) $connection->site_id);

        // Write the PUBLIC site_key to the shop's app-owned metafield so the theme extension
        // configures itself — the merchant never pastes the key into the theme editor.
        SyncShopMetafieldsJob::dispatch($accountId, (int) $connection->site_id);

        Log::info(self::LOG_INSTALLED, [
            'correlation_id' => $correlationId,
            'shop_domain' => $shopDomain,
            'account_id' => $accountId,
            'site_id' => (int) $connection->site_id,
            'flow' => $flow,
        ]);

        return $connection;
    }

    /**
     * Re-install of a shop we already know, arriving with NO Vsio session (the
     * merchant re-installed from the Shopify admin). The owning account is resolved
     * pre-bind by the routing lookup, so the existing connection is re-activated in
     * place. Returns null when the shop is unknown (-> direct attach or auto-provision).
     */
    public function reconnectKnownShop(string $shopDomain, ShopifyAccessToken $token, ?string $correlationId = null): ?ShopifyConnection
    {
        $accountId = $this->router->accountIdForShopDomain($shopDomain);

        if ($accountId === null) {
            return null;
        }

        $siteId = Tenant::run($accountId, fn (): ?int => ShopifyConnection::query()
            ->where('shop_domain', $shopDomain)
            ->value('site_id'));

        if ($siteId === null) {
            return null; // routing row without a readable connection: treat as unknown
        }

        return $this->connect(
            accountId: $accountId,
            siteId: (int) $siteId,
            shopDomain: $shopDomain,
            token: $token,
            flow: ShopifyOAuthState::FLOW_INSTALL_NEW_SHOP,
            correlationId: $correlationId,
        );
    }

    /**
     * Legacy compatibility for installs parked before callbacks attached directly.
     * The token is held ENCRYPTED and NOT tenant-bound until an authenticated account
     * claims it. New callback flows do not call this method.
     */
    public function park(string $shopDomain, ShopifyAccessToken $token, string $correlationId): string
    {
        $claimToken = ShopifyPendingInstall::generateClaimToken();

        ShopifyPendingInstall::updateOrCreate(
            ['shop_domain' => $shopDomain],
            [
                'claim_token_hash' => ShopifyPendingInstall::hashClaimToken($claimToken),
                'credentials' => $token->toCredentials(),
                'correlation_id' => $correlationId,
                'expires_at' => now()->addMinutes(ShopifyPendingInstall::TTL_MINUTES),
            ],
        );

        Log::info(self::LOG_PARKED, ['correlation_id' => $correlationId, 'shop_domain' => $shopDomain]);

        return $claimToken;
    }

    /**
     * Consume a parked install EXACTLY ONCE for an authenticated account: create (or
     * reuse) the Site inside Tenant::run, persist the connection, and DELETE the pending
     * row so the token can never be claimed twice.
     */
    public function claim(ShopifyPendingInstall $pending, int $accountId): ShopifyConnection
    {
        if ($pending->isExpired()) {
            throw ShopifyOAuthException::pendingInstallExpired();
        }

        $shopDomain = (string) $pending->shop_domain;
        $this->assertShopIsClaimableBy($accountId, $shopDomain);

        $token = new ShopifyAccessToken(
            accessToken: (string) $pending->accessToken(),
            scopes: (string) ($pending->credentials[ShopifyPendingInstall::CRED_SCOPES] ?? config('shopify.scopes')),
            apiVersion: (string) ($pending->credentials[ShopifyPendingInstall::CRED_API_VERSION] ?? config('shopify.api_version')),
        );

        $connection = $this->installFreshShop($accountId, $shopDomain, $token, (string) $pending->correlation_id);

        // Single-use: the parked token is gone the moment it is consumed.
        $pending->delete();

        Log::info(self::LOG_CLAIMED, [
            'correlation_id' => $pending->correlation_id,
            'shop_domain' => $shopDomain,
            'account_id' => $accountId,
        ]);

        return $connection;
    }

    /**
     * Create the Site (if needed) + persist the connection for an ALREADY-RESOLVED
     * account — the shared tail of both an authenticated claim and the Shopify-SSO
     * auto-provision. Reuses connect() (the ONE persist path, which re-checks the
     * cross-account wall); never duplicates it. The install_new_shop flow implies one
     * store = one Site.
     */
    public function installFreshShop(int $accountId, string $shopDomain, ShopifyAccessToken $token, ?string $correlationId = null): ShopifyConnection
    {
        $siteId = $this->resolveSiteForShop($accountId, $shopDomain);

        return $this->connect(
            accountId: $accountId,
            siteId: $siteId,
            shopDomain: $shopDomain,
            token: $token,
            flow: ShopifyOAuthState::FLOW_INSTALL_NEW_SHOP,
            correlationId: $correlationId,
        );
    }

    /** Merchant-initiated disconnect (the panel action). Credentials are wiped by the model. */
    public function disconnect(ShopifyConnection $connection): void
    {
        Tenant::run((int) $connection->account_id, function () use ($connection): void {
            if ($connection->isInstalled()) {
                $connection->transitionTo(ShopifyConnection::STATUS_UNINSTALLED, [self::DETAIL_FLOW => self::FLOW_MERCHANT_DISCONNECT]);
            }
        });

        Log::info(self::LOG_DISCONNECTED, [
            'shop_domain' => $connection->shop_domain,
            'account_id' => (int) $connection->account_id,
            'site_id' => (int) $connection->site_id,
        ]);
    }

    // === Internals ===

    /**
     * Make sure the STORE's own origin (https://{shop}.myshopify.com) passes the widget's
     * Origin allow-list. The middleware always allows the SITE's domain origin, but a site
     * connected to Shopify from the panel (connect_existing_site) may carry a different
     * domain — without this, the widget 403s silently on the storefront. Idempotent.
     */
    private function allowShopOrigin(Site $site, string $shopDomain): void
    {
        $shopOrigin = Site::originFromDomain($shopDomain);

        if ($shopOrigin === null || $shopOrigin === Site::originFromDomain($site->domain)) {
            return;
        }

        $origins = (array) ($site->allowed_origins ?? []);

        if (! in_array($shopOrigin, $origins, true)) {
            $origins[] = $shopOrigin;
            $site->allowed_origins = $origins;
        }
    }

    /**
     * The cross-account wall: a shop_domain already owned by ANOTHER account can never
     * be attached here. The lookup is the audited pre-bind router — only the integer
     * account_id crosses the tenant boundary, never a row or a token.
     */
    private function assertShopIsClaimableBy(int $accountId, string $shopDomain): void
    {
        if (! ShopifyOAuth::isValidShopDomain($shopDomain)) {
            throw ShopifyOAuthException::invalidShop($shopDomain);
        }

        $owner = $this->router->accountIdForShopDomain($shopDomain);

        if ($owner !== null && $owner !== $accountId) {
            throw ShopifyOAuthException::shopOwnedByAnotherAccount($shopDomain);
        }
    }

    /**
     * The site this shop installs into: the one already connected to it, else a fresh
     * Site created inside the tenant (the install_new_shop path — one store = one Site).
     */
    private function resolveSiteForShop(int $accountId, string $shopDomain): int
    {
        return Tenant::run($accountId, function () use ($shopDomain): int {
            $existing = ShopifyConnection::query()->where('shop_domain', $shopDomain)->value('site_id');

            if ($existing !== null) {
                return (int) $existing;
            }

            $site = Site::create([
                'name' => Str::headline(Str::before($shopDomain, '.')),
                'domain' => $shopDomain,
                'platform' => Site::PLATFORM_SHOPIFY,
            ]);

            return (int) $site->getKey();
        });
    }
}
