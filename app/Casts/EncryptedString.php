<?php

namespace App\Casts;

use App\Support\TenantCredentialsCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Encrypts a scalar string at rest using the TENANT_CREDENTIALS_KEY cipher
 * (separate from APP_KEY). The plaintext never touches the database column.
 *
 * Used for per-site widget_secret: ciphertext in the column, transparent
 * decrypt in PHP, and the value is never serialized to a browser response.
 */
final class EncryptedString implements CastsAttributes
{
    /** Decrypt on read. Null/empty stays null. */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return TenantCredentialsCipher::encrypter()->decrypt($value);
        } catch (Throwable $e) {
            // The key rotated (TENANT_CREDENTIALS_KEY changed) or the ciphertext is corrupt: degrade
            // to "unconfigured" rather than 500 the settings page / a generation worker. The admin
            // re-enters the secret and it re-encrypts with the current key. Logged so the ROOT cause
            // (an unstable env key) is diagnosable instead of a silent re-entry loop.
            Log::warning('encrypted_setting.decrypt_failed', [
                'model' => $model::class,
                'attribute' => $key,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Encrypt on write. Null stays null (column stores NULL, never ''). */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return TenantCredentialsCipher::encrypter()->encrypt($value);
    }
}
