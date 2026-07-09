<?php

namespace App\Domain\Platform;

use App\Models\PlatformSetting;
use Illuminate\Database\QueryException;

/**
 * PlatformSettings — the access layer for the global control-plane key/value store.
 *
 * READS are UNGUARDED config lookups (a generation worker reads the OpenRouter key
 * with no bound tenant / no authenticated user). Every consumer resolves a setting
 * as: DB value (super-admin entered in the UI) ELSE the env/config fallback — so a
 * value set in the Settings page wins, and an unset one transparently falls back to
 * the env var. WRITES go through put(), guarded by PlatformGuard (super-admin only).
 * Secret values are encrypted at rest by the PlatformSetting model (EncryptedString).
 */
final class PlatformSettings
{
    // === CONSTANTS ===
    // Setting keys (the DB `key` column). Stable identifiers, never magic strings.
    public const OPENROUTER_API_KEY = 'openrouter.api_key';
    public const BYTEPLUS_API_KEY = 'byteplus.api_key';
    public const XAI_API_KEY = 'xai.api_key';
    public const ATLASCLOUD_API_KEY = 'atlascloud.api_key';
    public const FAL_API_KEY = 'fal.api_key';
    public const PAYPLUS_API_KEY = 'payplus.api_key';
    public const PAYPLUS_SECRET_KEY = 'payplus.secret_key';
    public const PAYPLUS_PAGE_UID = 'payplus.page_uid';
    public const PAYPLUS_WEBHOOK_SECRET = 'payplus.webhook_secret';

    // SMTP outbound-mail settings (Super-Admin managed). Only SMTP_PASSWORD is a secret;
    // the rest are VISIBLE config values (host/port/encryption/username/from) the admin
    // sees and edits in the UI. Fallbacks point at Laravel's native mail.* config keys.
    public const SMTP_HOST = 'smtp.host';
    public const SMTP_PORT = 'smtp.port';
    public const SMTP_ENCRYPTION = 'smtp.encryption';
    public const SMTP_USERNAME = 'smtp.username';
    public const SMTP_PASSWORD = 'smtp.password';
    public const MAIL_FROM_ADDRESS = 'mail.from_address';
    public const MAIL_FROM_NAME = 'mail.from_name';

    // setting key → the config()/env fallback used when the DB value is unset.
    private const CONFIG_FALLBACK = [
        self::OPENROUTER_API_KEY => 'services.openrouter.key',
        self::BYTEPLUS_API_KEY => 'services.byteplus.api_key',
        self::XAI_API_KEY => 'services.xai.api_key',
        self::ATLASCLOUD_API_KEY => 'services.atlascloud.api_key',
        self::FAL_API_KEY => 'services.fal.api_key',
        self::PAYPLUS_API_KEY => 'services.payplus.api_key',
        self::PAYPLUS_SECRET_KEY => 'services.payplus.secret_key',
        self::PAYPLUS_PAGE_UID => 'services.payplus.page_uid',
        self::PAYPLUS_WEBHOOK_SECRET => 'services.payplus.webhook_secret',
        // Laravel-11 smtp mailer uses `scheme` (tls/ssl/null) for encryption.
        self::SMTP_HOST => 'mail.mailers.smtp.host',
        self::SMTP_PORT => 'mail.mailers.smtp.port',
        self::SMTP_ENCRYPTION => 'mail.mailers.smtp.scheme',
        self::SMTP_USERNAME => 'mail.mailers.smtp.username',
        self::SMTP_PASSWORD => 'mail.mailers.smtp.password',
        self::MAIL_FROM_ADDRESS => 'mail.from.address',
        self::MAIL_FROM_NAME => 'mail.from.name',
    ];

    /**
     * The effective value for a setting: the DB value if set, else the config/env
     * fallback. This is what every consumer (OpenRouterClient, PayPlusProvider) calls.
     */
    public function resolve(string $key): ?string
    {
        $stored = $this->get($key);

        if (filled($stored)) {
            return $stored;
        }

        $configKey = self::CONFIG_FALLBACK[$key] ?? null;
        $fallback = $configKey !== null ? config($configKey) : null;

        return $fallback !== null ? (string) $fallback : null;
    }

    /** The DB-stored (decrypted) value for a key, or null if not stored. */
    public function get(string $key): ?string
    {
        try {
            $setting = PlatformSetting::query()
                ->where(PlatformSetting::COLUMN_KEY, $key)
                ->first();
        } catch (QueryException) {
            // The settings store is unavailable (e.g. not yet migrated). A boundary
            // like the AI client must not break on this — fall back to the env var.
            return null;
        }

        return $setting?->value; // decrypted by the EncryptedString cast
    }

    /**
     * Store (or clear) a setting. Super-admin only. A NULL/blank value clears the row
     * (the consumer then falls back to the env var). Idempotent on the unique key.
     */
    public function put(string $key, ?string $value, bool $secret = true): void
    {
        PlatformGuard::assert();

        PlatformSetting::updateOrCreate(
            [PlatformSetting::COLUMN_KEY => $key],
            ['value' => $value !== null && $value !== '' ? $value : null, 'is_secret' => $secret],
        );
    }

    // A value left at the shipped env placeholder (e.g. OPENROUTER_API_KEY=
    // REPLACE_WITH_REAL_OPENROUTER_KEY) is NOT a real secret — treat it as unconfigured
    // so the setup checklist tells the truth instead of a false "configured".
    private const PLACEHOLDER_PREFIX = 'REPLACE';

    /** True if a REAL value exists for the key (DB OR env fallback, not the placeholder). */
    public function isConfigured(string $key): bool
    {
        $value = $this->resolve($key);

        return filled($value) && ! self::looksLikePlaceholder($value);
    }

    /** A shipped "REPLACE_WITH_…" placeholder is not a usable secret. */
    public static function looksLikePlaceholder(?string $value): bool
    {
        return $value !== null && str_starts_with(strtoupper(trim($value)), self::PLACEHOLDER_PREFIX);
    }

    /** True if the value was entered in the UI (a DB row with a value), vs only env. */
    public function isStoredInDb(string $key): bool
    {
        return filled($this->get($key));
    }
}
