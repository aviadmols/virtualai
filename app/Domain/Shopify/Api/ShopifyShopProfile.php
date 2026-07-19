<?php

namespace App\Domain\Shopify\Api;

use App\Domain\Shopify\Auth\ShopifyAccessToken;
use App\Models\ShopifyConnection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShopifyShopProfile — reads a store's name + contact email from the Admin API using a
 * freshly-minted offline token, BEFORE any ShopifyConnection is persisted (the identity
 * the Shopify-SSO auto-provision names the owner user with).
 *
 * BEST-EFFORT by design: the store email is an enrichment, not a dependency. Any failure
 * (throttle budget spent, transport error, a store that hides its email) returns an empty
 * profile so the install still completes on a deterministic shop-derived login rather than
 * failing at the OAuth callback.
 *
 * The token travels here in a TRANSIENT (unsaved) connection — a read-only carrier to the
 * one Admin-API door (ShopifyGraphQLClient). Nothing is persisted; the real connection is
 * written later, inside Tenant::run.
 */
final class ShopifyShopProfile
{
    // === CONSTANTS ===
    private const SHOP_QUERY = 'query { shop { name email contactEmail myshopifyDomain } }';

    private const KEY_SHOP = 'shop';

    private const KEY_NAME = 'name';

    private const KEY_EMAIL = 'email';

    private const KEY_CONTACT_EMAIL = 'contactEmail';

    private const LOG_UNAVAILABLE = 'shopify.shop_profile.unavailable';

    public function __construct(
        private readonly ShopifyGraphQLClient $client,
    ) {}

    /** The store's name + a usable contact email, or an empty profile when unreadable. */
    public function fetch(string $shopDomain, ShopifyAccessToken $token): ShopProfile
    {
        try {
            $data = $this->client->query($this->carrier($shopDomain, $token), self::SHOP_QUERY);
            $shop = (array) ($data[self::KEY_SHOP] ?? []);

            return new ShopProfile(
                name: $this->clean($shop[self::KEY_NAME] ?? null),
                // contactEmail is the merchant's account email; email is the store email.
                email: $this->clean($shop[self::KEY_CONTACT_EMAIL] ?? null)
                    ?? $this->clean($shop[self::KEY_EMAIL] ?? null),
            );
        } catch (Throwable) {
            Log::warning(self::LOG_UNAVAILABLE, ['shop_domain' => $shopDomain]);

            return ShopProfile::empty();
        }
    }

    /**
     * A transient (UNSAVED) connection carrying only the shop domain + offline token. The
     * Admin-API client reads shop_domain + accessToken() off it; nothing else, and it is
     * never persisted.
     */
    private function carrier(string $shopDomain, ShopifyAccessToken $token): ShopifyConnection
    {
        $connection = new ShopifyConnection;
        $connection->shop_domain = $shopDomain;
        $connection->credentials = $token->toCredentials();

        return $connection;
    }

    /** A trimmed non-empty string, or null. */
    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
