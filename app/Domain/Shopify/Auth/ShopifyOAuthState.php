<?php

namespace App\Domain\Shopify\Auth;

use Illuminate\Contracts\Session\Session;
use JsonException;

/**
 * ShopifyOAuthState — the signed, short-lived, SINGLE-USE, BROWSER-BOUND state nonce carried
 * through the Shopify authorize redirect and handed back on the callback.
 *
 * It is the CSRF wall of the install. Four independent checks, all fail-closed:
 *   1. SIGNATURE — HMAC-SHA256 over the payload with an APP_KEY-derived key (a derivation, so
 *      the raw APP_KEY is never used directly for this purpose).
 *   2. EXPIRY    — issued_at + TTL. A stale state is dead.
 *   3. SINGLE-USE + BROWSER-BOUND — the nonce is written to the ISSUING BROWSER'S SESSION and
 *      PULLED at verify. A replay finds nothing; a callback arriving in any OTHER browser finds
 *      nothing either, because that session never held the nonce.
 *   4. (in the callback) the authenticated account must BE the account the state names.
 *
 * Check 3 is what stops STORE THEFT. A signed, unexpired state is otherwise a bearer token that
 * NAMES an account: an attacker could mint one for their own account, phish a victim's store
 * admin into approving the genuine Shopify grant screen, and have the victim's store — and its
 * offline access token — persisted under the ATTACKER's account. Keeping the nonce in the
 * initiating session (Shopify's own guidance) means the victim's callback presents a state their
 * browser never issued, and it dies at the wall.
 *
 * The payload carries only routing facts ({flow, account_id?, site_id?, nonce, issued_at}) —
 * never a token, never a secret.
 */
final class ShopifyOAuthState
{
    // === CONSTANTS ===
    // The two first-class install origins (docs/shopify/DECISIONS.md §2).
    public const FLOW_CONNECT_EXISTING_SITE = 'connect_existing_site';

    public const FLOW_INSTALL_NEW_SHOP = 'install_new_shop';

    public const FLOWS = [
        self::FLOW_CONNECT_EXISTING_SITE,
        self::FLOW_INSTALL_NEW_SHOP,
    ];

    // Payload schema version — a shape change bumps this and old states stop verifying.
    private const VERSION = 1;

    // How long an issued state stays valid (seconds). The merchant only has to click
    // "Install" on Shopify's grant screen inside this window.
    private const TTL_SECONDS = 900;

    private const NONCE_BYTES = 16;

    // The session key the single-use nonce is parked under (pulled, never read, at verify).
    private const SESSION_NONCE_PREFIX = 'shopify.oauth.state.';

    // The HMAC key is DERIVED from APP_KEY (never APP_KEY itself) with this label.
    private const KEY_DERIVATION_LABEL = 'trayon.shopify.oauth.state.v1';

    private const HMAC_ALGO = 'sha256';

    private const SEPARATOR = '.';

    // Payload keys.
    private const K_VERSION = 'v';

    private const K_FLOW = 'f';

    private const K_ACCOUNT_ID = 'a';

    private const K_SITE_ID = 's';

    private const K_NONCE = 'n';

    private const K_ISSUED_AT = 't';

    /**
     * Issue a signed, single-use state for one install attempt, parked in the ISSUING browser's
     * session — only that browser can complete the install.
     *
     * @param  string  $flow  one of self::FLOWS
     */
    public function issue(string $flow, Session $session, ?int $accountId = null, ?int $siteId = null): string
    {
        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));

        $payload = [
            self::K_VERSION => self::VERSION,
            self::K_FLOW => $flow,
            self::K_ACCOUNT_ID => $accountId,
            self::K_SITE_ID => $siteId,
            self::K_NONCE => $nonce,
            self::K_ISSUED_AT => time(),
        ];

        $encoded = self::b64url(json_encode($payload, JSON_THROW_ON_ERROR));

        // The single-use registry lives in the SESSION: verify() PULLS it, so a replay finds
        // nothing — and a different browser never had it in the first place.
        $session->put(self::SESSION_NONCE_PREFIX.$nonce, true);

        return $encoded.self::SEPARATOR.self::b64url($this->sign($encoded));
    }

    /**
     * Verify a state handed back on the callback. Returns null on ANY failure — missing,
     * malformed, forged signature, expired, unknown flow, already used, or issued to a DIFFERENT
     * browser than the one now presenting it.
     */
    public function verify(?string $state, Session $session): ?ShopifyOAuthStatePayload
    {
        if ($state === null || $state === '') {
            return null;
        }

        $parts = explode(self::SEPARATOR, $state);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        // Constant-time signature check FIRST — never parse an unverified payload.
        if (! hash_equals($this->sign($encoded), (string) self::b64urlDecode($signature))) {
            return null;
        }

        try {
            $payload = json_decode((string) self::b64urlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload[self::K_VERSION] ?? null) !== self::VERSION) {
            return null;
        }

        $flow = (string) ($payload[self::K_FLOW] ?? '');

        if (! in_array($flow, self::FLOWS, true)) {
            return null;
        }

        $issuedAt = (int) ($payload[self::K_ISSUED_AT] ?? 0);

        if ($issuedAt <= 0 || (time() - $issuedAt) > self::TTL_SECONDS) {
            return null;
        }

        $nonce = (string) ($payload[self::K_NONCE] ?? '');

        // Single-use AND browser-bound: the nonce is consumed from THIS session. A replay pulls
        // nothing; a callback in a stranger's browser pulls nothing.
        if ($nonce === '' || $session->pull(self::SESSION_NONCE_PREFIX.$nonce) !== true) {
            return null;
        }

        $accountId = $payload[self::K_ACCOUNT_ID] ?? null;
        $siteId = $payload[self::K_SITE_ID] ?? null;

        return new ShopifyOAuthStatePayload(
            flow: $flow,
            accountId: $accountId === null ? null : (int) $accountId,
            siteId: $siteId === null ? null : (int) $siteId,
            nonce: $nonce,
            issuedAt: $issuedAt,
        );
    }

    // === Internals ===

    /** Raw HMAC over the encoded payload with the APP_KEY-derived key. */
    private function sign(string $encoded): string
    {
        return hash_hmac(self::HMAC_ALGO, $encoded, self::derivedKey(), true);
    }

    /** The signing key: HMAC(label, APP_KEY) — a derivation, never the raw APP_KEY. */
    private static function derivedKey(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac(self::HMAC_ALGO, self::KEY_DERIVATION_LABEL, $appKey, true);
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
