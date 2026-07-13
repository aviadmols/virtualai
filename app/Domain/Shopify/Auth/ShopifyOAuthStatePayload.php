<?php

namespace App\Domain\Shopify\Auth;

/**
 * ShopifyOAuthStatePayload — the verified contents of an OAuth state nonce.
 *
 * Produced ONLY by ShopifyOAuthState::verify() (signature + expiry + single-use all
 * passed). accountId/siteId are present for the connect_existing_site flow and null
 * for install_new_shop (there is no Tray On account yet at that point).
 */
final class ShopifyOAuthStatePayload
{
    public function __construct(
        public readonly string $flow,
        public readonly ?int $accountId,
        public readonly ?int $siteId,
        public readonly string $nonce,
        public readonly int $issuedAt,
    ) {}

    public function isConnectExistingSite(): bool
    {
        return $this->flow === ShopifyOAuthState::FLOW_CONNECT_EXISTING_SITE;
    }

    public function isInstallNewShop(): bool
    {
        return $this->flow === ShopifyOAuthState::FLOW_INSTALL_NEW_SHOP;
    }
}
