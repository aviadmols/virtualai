<?php

namespace App\Casts;

use App\Support\TenantCredentialsCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Encrypts a JSON-serializable array at rest using the TENANT_CREDENTIALS_KEY
 * cipher (separate from APP_KEY). The plaintext JSON never touches the column.
 *
 * For future per-site credential blobs (e.g. signed-webhook secrets, OAuth
 * tokens) that group several secret fields. widget_secret itself is a scalar
 * and uses EncryptedString.
 */
final class EncryptedJson implements CastsAttributes
{
    /** Decrypt + json_decode on read. Null stays null. */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return json_decode(
            TenantCredentialsCipher::encrypter()->decrypt($value),
            associative: true,
        );
    }

    /** json_encode + encrypt on write. Null stays null. */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return TenantCredentialsCipher::encrypter()->encrypt(
            json_encode($value, JSON_THROW_ON_ERROR),
        );
    }
}
