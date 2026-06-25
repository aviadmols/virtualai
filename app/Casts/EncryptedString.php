<?php

namespace App\Casts;

use App\Support\TenantCredentialsCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

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

        return TenantCredentialsCipher::encrypter()->decrypt($value);
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
