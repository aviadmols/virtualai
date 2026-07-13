<?php

namespace App\Domain\Shopify\Api;

use RuntimeException;

/**
 * ShopifyApiException — the TYPED failure of an Admin API call. Carries a stable code
 * plus the HTTP status, so a caller can branch (401 -> needs_reauth in Phase 7, 429 ->
 * back off) without parsing strings. Never carries the access token or the raw body.
 */
final class ShopifyApiException extends RuntimeException
{
    // === CONSTANTS ===
    public const CODE_TRANSPORT = 'transport_error';

    public const CODE_UNAUTHORIZED = 'unauthorized';   // 401 — token revoked/expired

    public const CODE_THROTTLED = 'throttled';         // 429 — cost/rate limit

    public const CODE_HTTP = 'http_error';

    public const CODE_GRAPHQL = 'graphql_error';

    public const CODE_NO_TOKEN = 'no_access_token';

    private const STATUS_UNAUTHORIZED = 401;

    private const STATUS_THROTTLED = 429;

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $shop, string $exceptionClass): self
    {
        return new self(self::CODE_TRANSPORT, sprintf('Could not reach the Shopify Admin API for %s (%s).', $shop, $exceptionClass));
    }

    public static function noAccessToken(string $shop): self
    {
        return new self(self::CODE_NO_TOKEN, sprintf('No offline access token stored for %s (re-connect required).', $shop));
    }

    /** Map an HTTP status to the typed code (401/429 are the two the caller branches on). */
    public static function http(string $shop, int $status): self
    {
        $code = match ($status) {
            self::STATUS_UNAUTHORIZED => self::CODE_UNAUTHORIZED,
            self::STATUS_THROTTLED => self::CODE_THROTTLED,
            default => self::CODE_HTTP,
        };

        return new self($code, sprintf('Shopify Admin API returned HTTP %d for %s.', $status, $shop), $status);
    }

    /** GraphQL 200 with an `errors` array (a valid HTTP call, an invalid query). */
    public static function graphql(string $shop, string $firstMessage): self
    {
        return new self(self::CODE_GRAPHQL, sprintf('Shopify GraphQL error for %s: %s', $shop, $firstMessage), 200);
    }

    public function isUnauthorized(): bool
    {
        return $this->errorCode === self::CODE_UNAUTHORIZED;
    }

    public function isThrottled(): bool
    {
        return $this->errorCode === self::CODE_THROTTLED;
    }
}
