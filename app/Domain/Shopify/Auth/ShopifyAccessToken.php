<?php

namespace App\Domain\Shopify\Auth;

use App\Models\ShopifyConnection;

/**
 * ShopifyAccessToken — the result of a successful code -> offline-token exchange.
 *
 * A value object, never a model attribute bag: the token only ever travels from
 * ShopifyOAuth::exchangeCode() to ShopifyInstaller, which writes it through the
 * EncryptedJson credentials cast. It is never logged and never serialized.
 */
final class ShopifyAccessToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $scopes,
        public readonly string $apiVersion,
    ) {}

    /** The encrypted-credentials blob shape stored on ShopifyConnection. */
    public function toCredentials(): array
    {
        return [
            ShopifyConnection::CRED_ACCESS_TOKEN => $this->accessToken,
            ShopifyConnection::CRED_SCOPES => $this->scopes,
            ShopifyConnection::CRED_API_VERSION => $this->apiVersion,
        ];
    }
}
