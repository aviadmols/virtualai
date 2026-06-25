<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * The encrypter for per-site credentials (widget_secret, future tenant secrets).
 *
 * Keyed by TENANT_CREDENTIALS_KEY — deliberately SEPARATE from APP_KEY so the
 * credential key can be rotated independently of the app key. The key never
 * lands in a tenant-readable column; it lives only in server env.
 */
final class TenantCredentialsCipher
{
    // === CONSTANTS ===
    private const CIPHER = 'aes-256-cbc';
    private const KEY_CONFIG = 'trayon.credentials_key';
    private const BASE64_PREFIX = 'base64:';

    private static ?Encrypter $encrypter = null;

    /** The lazily-built, key-rotated encrypter for tenant credentials. */
    public static function encrypter(): Encrypter
    {
        return self::$encrypter ??= new Encrypter(self::key(), self::CIPHER);
    }

    /** Reset the memoized encrypter — used by tests after swapping the key. */
    public static function flush(): void
    {
        self::$encrypter = null;
    }

    /** Resolve the binary key from config, accepting the base64: prefix form. */
    private static function key(): string
    {
        $raw = config(self::KEY_CONFIG);

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('TENANT_CREDENTIALS_KEY is not set; cannot encrypt tenant credentials.');
        }

        if (str_starts_with($raw, self::BASE64_PREFIX)) {
            $raw = base64_decode(substr($raw, strlen(self::BASE64_PREFIX)), true);

            if ($raw === false) {
                throw new RuntimeException('TENANT_CREDENTIALS_KEY is not valid base64.');
            }
        }

        return $raw;
    }
}
