<?php

namespace App\Http\Shopify;

use App\Domain\Shopify\Auth\ShopifySessionTokenPayload;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * ShopifyEmbeddedContext — the resolved auth context for one embedded-admin request.
 *
 * VerifyShopifySessionToken verifies the App Bridge JWT, binds the tenant, resolves the
 * connection + site + owner, and stashes this on the request. Controllers read it via
 * ShopifyEmbeddedContext::of($request) instead of re-resolving — the account is the
 * connection's account, NEVER anything the browser claims.
 */
final readonly class ShopifyEmbeddedContext
{
    // === CONSTANTS ===
    public const REQUEST_ATTRIBUTE = 'tray_shopify_embedded_context';

    public function __construct(
        public ShopifyConnection $connection,
        public Site $site,
        public User $owner,
        public ShopifySessionTokenPayload $token,
    ) {}

    public function accountId(): int
    {
        return (int) $this->connection->account_id;
    }

    public function shopDomain(): string
    {
        return (string) $this->connection->shop_domain;
    }

    /** Stash this context on the request for the controllers behind the middleware. */
    public function bindTo(Request $request): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $this);
    }

    /** Read the context the middleware stashed; fails loud if the middleware did not run. */
    public static function of(Request $request): self
    {
        $context = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! $context instanceof self) {
            throw new \RuntimeException('ShopifyEmbeddedContext missing: the session-token middleware did not run.');
        }

        return $context;
    }
}
