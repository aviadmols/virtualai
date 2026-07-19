<?php

namespace App\Domain\Shopify\Auth;

/**
 * ShopifySessionTokenPayload — the verified claims of one App Bridge session token.
 *
 * Produced ONLY by ShopifySessionToken::verify(); holds routing facts, never a secret.
 * shopDomain is the canonical `{name}.myshopify.com` host parsed from the `dest` claim.
 */
final readonly class ShopifySessionTokenPayload
{
    public function __construct(
        public string $shopDomain,
        public string $userId,
        public int $expiresAt,
        public ?string $sessionId = null,
    ) {}
}
