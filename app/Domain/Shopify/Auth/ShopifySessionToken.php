<?php

namespace App\Domain\Shopify\Auth;

use App\Domain\Shopify\ShopifyCredentials;
use JsonException;

/**
 * ShopifySessionToken — the App Bridge session-token (JWT, HS256) verifier.
 *
 * The embedded admin surface authenticates every request with a short-lived JWT that
 * Shopify's App Bridge mints in the merchant's browser and signs with OUR client secret.
 * This class is the ONLY verifier. Hand-rolled on purpose (the same b64url + hash_equals
 * primitives as ShopifyOAuthState) — no JWT library, no algorithm negotiation surface.
 *
 * Fail-closed: EVERY check below returns null on failure, and the SIGNATURE is checked
 * FIRST, constant-time, before any payload byte is parsed. The claims walls, in order:
 *
 *   shape     three non-empty dot-separated parts;
 *   secret    unset app credentials never verify anything (mirrors verifyRequestHmac);
 *   signature HMAC-SHA256(header.payload, client_secret), hash_equals;
 *   header    alg === HS256 exactly (algorithm-confusion wall) and typ === JWT;
 *   exp/nbf   validity window with a small clock-skew leeway;
 *   aud       must equal OUR client_id (a token minted for another app dies here);
 *   dest      must be https://{name}.myshopify.com — the SAME anchored regex the OAuth
 *             callback trusts (ShopifyOAuth::isValidShopDomain);
 *   iss       must equal dest + '/admin' (a token for shop A can never present as shop B);
 *   sub       non-empty (the shop user id, carried for logging only).
 */
final class ShopifySessionToken
{
    // === CONSTANTS ===
    private const HMAC_ALGO = 'sha256';

    private const SEPARATOR = '.';

    private const PARTS = 3;

    // The ONLY acceptable header values. Anything else — 'none', RS256, a missing typ —
    // is hostile or malformed and dies before the claims are even considered.
    private const HEADER_ALG = 'HS256';

    private const HEADER_TYP = 'JWT';

    // Clock-skew leeway on exp/nbf (seconds). Shopify tokens live ~60s; a small leeway
    // absorbs skew without meaningfully extending the window.
    private const LEEWAY_SECONDS = 5;

    private const SCHEME = 'https://';

    private const ISS_SUFFIX = '/admin';

    // Header keys.
    private const H_ALG = 'alg';

    private const H_TYP = 'typ';

    // Claim keys.
    private const C_ISS = 'iss';

    private const C_DEST = 'dest';

    private const C_AUD = 'aud';

    private const C_SUB = 'sub';

    private const C_EXP = 'exp';

    private const C_NBF = 'nbf';

    private const C_SID = 'sid';

    public function __construct(
        private readonly ShopifyCredentials $credentials,
    ) {}

    /**
     * Verify a session token. Returns the typed payload, or null on ANY failure —
     * the caller never learns which wall rejected it.
     */
    public function verify(?string $token): ?ShopifySessionTokenPayload
    {
        if ($token === null || $token === '') {
            return null;
        }

        $parts = explode(self::SEPARATOR, $token);

        if (count($parts) !== self::PARTS || in_array('', $parts, true)) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Unset OR shipped-placeholder credentials never verify anything (fail closed):
        // a REPLACE_... secret is publicly known, so treating it as a live HMAC key would
        // let anyone forge a token. isConfigured() rejects both empty and placeholder.
        if (! $this->credentials->isConfigured()) {
            return null;
        }

        $secret = $this->credentials->clientSecret();

        // Signature FIRST, constant-time — never parse an unverified payload.
        $expected = self::b64url(hash_hmac(self::HMAC_ALGO, $headerB64.self::SEPARATOR.$payloadB64, $secret, true));

        if (! hash_equals($expected, rtrim($signatureB64, '='))) {
            return null;
        }

        $header = self::decodeJson($headerB64);

        if ($header === null
            || ($header[self::H_ALG] ?? null) !== self::HEADER_ALG
            || ($header[self::H_TYP] ?? null) !== self::HEADER_TYP) {
            return null;
        }

        $claims = self::decodeJson($payloadB64);

        if ($claims === null) {
            return null;
        }

        $now = time();
        $exp = $claims[self::C_EXP] ?? null;

        if (! is_int($exp) || $exp <= 0 || $now >= $exp + self::LEEWAY_SECONDS) {
            return null;
        }

        $nbf = $claims[self::C_NBF] ?? null;

        if (! is_int($nbf) || $nbf <= 0 || $now < $nbf - self::LEEWAY_SECONDS) {
            return null;
        }

        $aud = $claims[self::C_AUD] ?? null;
        $clientId = $this->credentials->clientId();

        if (! is_string($aud) || $clientId === '' || ! hash_equals($clientId, $aud)) {
            return null;
        }

        // is_string guards before any (string) cast: a signed-but-malformed claim that is
        // a JSON array/object must fail closed, not raise a PHP "Array to string" warning.
        $dest = $claims[self::C_DEST] ?? null;

        if (! is_string($dest) || ! str_starts_with($dest, self::SCHEME)) {
            return null;
        }

        $shop = substr($dest, strlen(self::SCHEME));

        if (! ShopifyOAuth::isValidShopDomain($shop)) {
            return null;
        }

        // iss must be the SAME shop's admin — binds the token to exactly one store.
        if (($claims[self::C_ISS] ?? null) !== $dest.self::ISS_SUFFIX) {
            return null;
        }

        $sub = $claims[self::C_SUB] ?? null;

        if (! is_string($sub) || $sub === '') {
            return null;
        }

        $sid = $claims[self::C_SID] ?? null;

        return new ShopifySessionTokenPayload(
            shopDomain: $shop,
            userId: $sub,
            expiresAt: $exp,
            sessionId: is_string($sid) && $sid !== '' ? $sid : null,
        );
    }

    // === Internals ===

    /** Decode one b64url JWT segment to an array, or null on any malformation. */
    private static function decodeJson(string $segment): ?array
    {
        $raw = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
