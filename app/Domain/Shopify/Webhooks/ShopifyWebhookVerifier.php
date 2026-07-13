<?php

namespace App\Domain\Shopify\Webhooks;

use App\Domain\Shopify\Auth\ShopifyOAuth;
use App\Domain\Shopify\ShopifyCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ShopifyWebhookVerifier — the signature wall of the webhook intake.
 *
 * Exactly the PayPlusProvider::verifyAndParseWebhook shape (the same scheme Shopify
 * uses): base64(HMAC-SHA256(RAW BODY, client_secret)) compared with hash_equals against
 * the X-Shopify-Hmac-Sha256 header. It FAILS CLOSED on a missing secret (misconfigured
 * app), a missing header (unsigned/forged), a mismatch, or a shop domain that is not a
 * canonical *.myshopify.com host.
 *
 * The RAW body is what is signed — never the re-encoded/parsed array (re-encoding
 * changes bytes and silently breaks the signature). No secret is ever logged.
 */
final class ShopifyWebhookVerifier
{
    // === CONSTANTS ===
    public const HEADER_HMAC = 'X-Shopify-Hmac-Sha256';

    public const HEADER_TOPIC = 'X-Shopify-Topic';

    public const HEADER_SHOP_DOMAIN = 'X-Shopify-Shop-Domain';

    public const HEADER_WEBHOOK_ID = 'X-Shopify-Webhook-Id';

    private const HMAC_ALGO = 'sha256';

    private const LOG_UNSIGNED = 'shopify.webhook.unsigned';

    private const LOG_BAD_SIGNATURE = 'shopify.webhook.bad_signature';

    private const LOG_BAD_SHOP = 'shopify.webhook.bad_shop';

    public function __construct(
        private readonly ShopifyCredentials $credentials,
    ) {}

    /** True only for a genuinely Shopify-signed delivery from a valid shop host. */
    public function verify(Request $request): bool
    {
        $secret = $this->credentials->clientSecret();
        $sent = (string) $request->header(self::HEADER_HMAC, '');

        if ($secret === '' || $sent === '') {
            Log::warning(self::LOG_UNSIGNED, [
                'has_secret' => $secret !== '',
                'has_hmac' => $sent !== '',
                'shop_domain' => $request->header(self::HEADER_SHOP_DOMAIN),
            ]);

            return false;
        }

        if (! ShopifyOAuth::isValidShopDomain($request->header(self::HEADER_SHOP_DOMAIN))) {
            Log::warning(self::LOG_BAD_SHOP, ['shop_domain' => $request->header(self::HEADER_SHOP_DOMAIN)]);

            return false;
        }

        $expected = base64_encode(hash_hmac(self::HMAC_ALGO, $request->getContent(), $secret, true));

        if (! hash_equals($expected, $sent)) {
            Log::warning(self::LOG_BAD_SIGNATURE, ['shop_domain' => $request->header(self::HEADER_SHOP_DOMAIN)]);

            return false;
        }

        return true;
    }

    /** The signature a Shopify delivery of this raw body would carry (test/tooling helper). */
    public static function signature(string $rawBody, string $secret): string
    {
        return base64_encode(hash_hmac(self::HMAC_ALGO, $rawBody, $secret, true));
    }
}
