<?php

namespace App\Domain\Shopify;

use App\Domain\Platform\PlatformSettings;

/**
 * ShopifyCredentials — the ONE resolver for the APP-level Shopify credentials
 * (Partner Dashboard client id + client secret).
 *
 * Resolution order is PlatformSettings::resolve — the super-admin's DB value wins,
 * else the config/services.php (env) fallback. No call site reads env() directly, so
 * rotating the secret is a Settings-page change, not a redeploy.
 *
 * The client SECRET is also the webhook HMAC key and the token-exchange credential:
 * it is server-side only and is NEVER logged, serialized, or returned to a browser.
 * Unset credentials are a TYPED "not configured" state (isConfigured() === false) —
 * never a 500 at a call site.
 */
final class ShopifyCredentials
{
    public function __construct(
        private readonly PlatformSettings $settings,
    ) {}

    /** The Partner-Dashboard client id (public). Empty string when unset. */
    public function clientId(): string
    {
        return (string) ($this->settings->resolve(PlatformSettings::SHOPIFY_CLIENT_ID) ?? '');
    }

    /** The Partner-Dashboard client secret (SECRET: token exchange + webhook HMAC). */
    public function clientSecret(): string
    {
        return (string) ($this->settings->resolve(PlatformSettings::SHOPIFY_CLIENT_SECRET) ?? '');
    }

    /** True only when BOTH credentials hold a real (non-placeholder) value. */
    public function isConfigured(): bool
    {
        return $this->settings->isConfigured(PlatformSettings::SHOPIFY_CLIENT_ID)
            && $this->settings->isConfigured(PlatformSettings::SHOPIFY_CLIENT_SECRET);
    }
}
