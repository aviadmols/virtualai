<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;

/**
 * PlatformSetting — a global control-plane key/value row (Super-Admin managed).
 *
 * GLOBAL (on GlobalModels::ALLOW_LIST) — NOT BelongsToAccount: it is platform-wide
 * config, not tenant data, and is read from contexts with no bound tenant (e.g. a
 * generation worker reading the OpenRouter key). Secret values are encrypted at rest
 * via the EncryptedString cast (TENANT_CREDENTIALS_KEY cipher) and never serialized
 * to a browser. Reads are unguarded (config); writes go through PlatformSettings
 * (PlatformGuard, super-admin only).
 */
class PlatformSetting extends Model
{
    // === CONSTANTS ===
    public const COLUMN_KEY = 'key';

    protected $fillable = [
        'key',
        'value',
        'is_secret',
    ];

    /** value is encrypted at rest; never serialize the secret to a response. */
    protected $hidden = [
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => EncryptedString::class,
            'is_secret' => 'boolean',
        ];
    }
}
