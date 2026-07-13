<?php

namespace App\Domain\Ai;

/**
 * KlingJwt — the Kling (Kuaishou) API auth token.
 *
 * Kling does not take a static API key: the developer console issues an ACCESS KEY (ak) + a
 * SECRET KEY (sk), and every request carries a short-lived JWT signed with the secret:
 *
 *   header  {"alg":"HS256","typ":"JWT"}
 *   payload {"iss": <access_key>, "exp": now+1800, "nbf": now-5}
 *   sent as `Authorization: Bearer <jwt>`
 *
 * Signed here with hash_hmac (no JWT dependency — the token is three base64url segments). The
 * 5-second `nbf` backdate absorbs clock skew between us and Kling; the 30-minute `exp` is Kling's
 * documented lifetime. A token is minted per request (cheap) so it can never be served stale.
 *
 * The SECRET KEY never leaves the server and never appears in a log — only the signature does.
 */
final class KlingJwt
{
    // === CONSTANTS ===
    private const ALGORITHM = 'HS256';

    private const TYPE = 'JWT';

    private const HMAC_ALGO = 'sha256';

    // Kling's documented token lifetime, and the skew backdate on nbf.
    private const TTL_SECONDS = 1800;

    private const NOT_BEFORE_SKEW_SECONDS = 5;

    /** Sign a fresh bearer token for this access/secret pair. Returns '' when either is missing. */
    public static function token(string $accessKey, string $secretKey, ?int $now = null): string
    {
        if ($accessKey === '' || $secretKey === '') {
            return '';
        }

        $now ??= time();

        $header = self::segment(['alg' => self::ALGORITHM, 'typ' => self::TYPE]);
        $payload = self::segment([
            'iss' => $accessKey,
            'exp' => $now + self::TTL_SECONDS,
            'nbf' => $now - self::NOT_BEFORE_SKEW_SECONDS,
        ]);

        $signature = self::base64Url(
            hash_hmac(self::HMAC_ALGO, $header.'.'.$payload, $secretKey, binary: true),
        );

        return $header.'.'.$payload.'.'.$signature;
    }

    /** @param array<string,mixed> $claims */
    private static function segment(array $claims): string
    {
        return self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
