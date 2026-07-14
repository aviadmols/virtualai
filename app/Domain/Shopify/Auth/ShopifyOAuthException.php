<?php

namespace App\Domain\Shopify\Auth;

use RuntimeException;

/**
 * ShopifyOAuthException — the TYPED failure of an install. Every named constructor
 * carries a stable machine code the controller maps to an HTTP status (403 for a
 * tampered/forbidden install, 409 for a conflict, 502 for a provider failure).
 *
 * No message ever carries a token, a secret, or a raw provider body — only the code,
 * the shop domain, and a human line.
 */
final class ShopifyOAuthException extends RuntimeException
{
    // === CONSTANTS ===
    // Forbidden (403) — tampering, a bad shop, or an install that is not this account's.
    public const CODE_INVALID_SHOP = 'invalid_shop';

    public const CODE_INVALID_HMAC = 'invalid_hmac';

    public const CODE_INVALID_STATE = 'invalid_state';

    public const CODE_SHOP_OWNED_BY_ANOTHER_ACCOUNT = 'shop_owned_by_another_account';

    public const CODE_NO_ACCOUNT = 'no_account';

    public const CODE_SITE_NOT_OWNED = 'site_not_owned';

    public const CODE_MISSING_CODE = 'missing_code';

    // Conflict (409) — the target site already carries a different store.
    public const CODE_SITE_ALREADY_CONNECTED = 'site_already_connected';

    public const CODE_PENDING_INSTALL_EXPIRED = 'pending_install_expired';

    // Bad gateway (502) — the app is not configured, or Shopify rejected the exchange.
    public const CODE_NOT_CONFIGURED = 'not_configured';

    public const CODE_TOKEN_EXCHANGE_FAILED = 'token_exchange_failed';

    // code => the HTTP status the OAuth controller answers with. A tampered install is
    // ALWAYS 403 and NEVER a 500 (the locked Phase-2 contract).
    public const HTTP_STATUS = [
        self::CODE_INVALID_SHOP => 403,
        self::CODE_INVALID_HMAC => 403,
        self::CODE_INVALID_STATE => 403,
        self::CODE_SHOP_OWNED_BY_ANOTHER_ACCOUNT => 403,
        self::CODE_NO_ACCOUNT => 403,
        self::CODE_SITE_NOT_OWNED => 403,
        self::CODE_MISSING_CODE => 403,
        self::CODE_SITE_ALREADY_CONNECTED => 409,
        self::CODE_PENDING_INSTALL_EXPIRED => 409,
        self::CODE_NOT_CONFIGURED => 502,
        self::CODE_TOKEN_EXCHANGE_FAILED => 502,
    ];

    private const DEFAULT_STATUS = 403;

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $shopDomain = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidShop(?string $shop): self
    {
        return new self(self::CODE_INVALID_SHOP, 'Not a valid *.myshopify.com shop domain.', $shop);
    }

    public static function invalidHmac(?string $shop): self
    {
        return new self(self::CODE_INVALID_HMAC, 'The request signature did not verify.', $shop);
    }

    public static function invalidState(?string $shop): self
    {
        return new self(self::CODE_INVALID_STATE, 'The OAuth state nonce is missing, forged, expired, or already used.', $shop);
    }

    public static function shopOwnedByAnotherAccount(string $shop): self
    {
        return new self(self::CODE_SHOP_OWNED_BY_ANOTHER_ACCOUNT, 'This Shopify store is already connected to another Vsio account.', $shop);
    }

    public static function noAccount(): self
    {
        return new self(self::CODE_NO_ACCOUNT, 'The authenticated user does not belong to a merchant account.');
    }

    /** The site id does not resolve inside the caller's tenant (fail-closed scope). */
    public static function siteNotOwned(): self
    {
        return new self(self::CODE_SITE_NOT_OWNED, 'That site does not belong to your account.');
    }

    public static function missingCode(?string $shop): self
    {
        return new self(self::CODE_MISSING_CODE, 'The Shopify callback carried no authorization code.', $shop);
    }

    public static function siteAlreadyConnected(string $shop): self
    {
        return new self(self::CODE_SITE_ALREADY_CONNECTED, 'This site is already connected to a different Shopify store.', $shop);
    }

    public static function pendingInstallExpired(): self
    {
        return new self(self::CODE_PENDING_INSTALL_EXPIRED, 'The pending install has expired. Start the install from Shopify again.');
    }

    public static function notConfigured(): self
    {
        return new self(self::CODE_NOT_CONFIGURED, 'The Shopify app credentials are not configured.');
    }

    public static function tokenExchangeFailed(string $shop, int $status): self
    {
        return new self(self::CODE_TOKEN_EXCHANGE_FAILED, sprintf('Shopify rejected the token exchange (HTTP %d).', $status), $shop);
    }

    /** The HTTP status this failure answers with. */
    public function httpStatus(): int
    {
        return self::HTTP_STATUS[$this->errorCode] ?? self::DEFAULT_STATUS;
    }
}
