<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use Database\Factories\ShopifyPendingInstallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ShopifyPendingInstall — an install that started ON SHOPIFY, parked until a Tray On
 * account exists to own it (docs/shopify/DECISIONS.md §2, `install_new_shop`).
 *
 * PLATFORM-level (GlobalModels::ALLOW_LIST): the row is created PRE-BIND — at OAuth
 * callback time the merchant has no account yet, so there is no tenant to bind. The
 * same documented exception class as ShopifyWebhookReceipt / the SiteRouter lookup:
 * NOTHING tenant-owned is written here, and the row is consumed EXACTLY ONCE by an
 * authenticated account (ShopifyInstaller::claim) and then DELETED.
 *
 * Defense in depth: the offline token is encrypted at rest (EncryptedJson under
 * TENANT_CREDENTIALS_KEY) and hidden from serialization; the claim token is stored as
 * a sha256 HASH, so the row alone cannot claim the install; the row expires.
 */
class ShopifyPendingInstall extends Model
{
    /** @use HasFactory<ShopifyPendingInstallFactory> */
    use HasFactory;

    // === CONSTANTS ===
    // How long a parked install stays claimable (the merchant registers/logs in inside it).
    public const TTL_MINUTES = 60;

    // Bytes of entropy in the opaque claim token handed to the browser.
    public const CLAIM_TOKEN_BYTES = 32;

    private const CLAIM_TOKEN_ALGO = 'sha256';

    // Keys inside the encrypted `credentials` blob (mirrors ShopifyConnection).
    public const CRED_ACCESS_TOKEN = ShopifyConnection::CRED_ACCESS_TOKEN;

    public const CRED_SCOPES = ShopifyConnection::CRED_SCOPES;

    public const CRED_API_VERSION = ShopifyConnection::CRED_API_VERSION;

    protected $fillable = [
        'shop_domain',
        'claim_token_hash',
        'credentials',
        'correlation_id',
        'expires_at',
    ];

    /** The offline token never leaves the server via serialization. */
    protected $hidden = [
        'credentials',
        'claim_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => EncryptedJson::class,
            'expires_at' => 'datetime',
        ];
    }

    /** A fresh opaque claim token (the plaintext is handed to the browser ONCE). */
    public static function generateClaimToken(): string
    {
        return bin2hex(random_bytes(self::CLAIM_TOKEN_BYTES));
    }

    /** The stored (hashed) form of a claim token. */
    public static function hashClaimToken(string $plain): string
    {
        return hash(self::CLAIM_TOKEN_ALGO, $plain);
    }

    /**
     * The claimable row for this plaintext token, or null when the token is unknown or
     * the parked install has expired (fail-closed — an expired row is never consumed).
     */
    public static function findClaimable(?string $plainToken): ?self
    {
        if ($plainToken === null || $plainToken === '') {
            return null;
        }

        $pending = self::query()
            ->where('claim_token_hash', self::hashClaimToken($plainToken))
            ->first();

        return $pending !== null && ! $pending->isExpired() ? $pending : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** The decrypted offline access token parked for this shop. */
    public function accessToken(): ?string
    {
        $token = $this->credentials[self::CRED_ACCESS_TOKEN] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
