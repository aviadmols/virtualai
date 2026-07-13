<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ShopifyConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ShopifyConnection — one Shopify store's link to a Site (1:1). Tenant-owned
 * (BelongsToAccount).
 *
 * `shop_domain` is the globally-unique pre-bind webhook routing key (looked up by
 * ShopifyShopRouter). `credentials` is the encrypted per-store secret blob (offline
 * access token, granted scopes, API version at install) — EncryptedJson under
 * TENANT_CREDENTIALS_KEY, hidden from every serialization.
 *
 * Guarded status machine: installed <-> uninstalled (a re-install re-activates the
 * SAME row — a shop_domain never duplicates). Uninstalling wipes the credentials.
 */
class ShopifyConnection extends Model
{
    /** @use HasFactory<ShopifyConnectionFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    public const STATUS_INSTALLED = 'installed';

    public const STATUS_UNINSTALLED = 'uninstalled';

    public const TRANSITIONS = [
        self::STATUS_INSTALLED => [self::STATUS_UNINSTALLED],
        self::STATUS_UNINSTALLED => [self::STATUS_INSTALLED], // re-install re-activates
    ];

    // Keys inside the encrypted `credentials` blob.
    public const CRED_ACCESS_TOKEN = 'access_token';

    public const CRED_SCOPES = 'scopes';

    public const CRED_API_VERSION = 'api_version';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal Shopify-connection status transition %s -> %s (connection #%s).';

    protected $fillable = [
        'site_id',
        'shop_domain',
        'status',
        'credentials',
        'needs_reauth',
        'webhook_registration',
        'installed_at',
        'uninstalled_at',
    ];

    /** The offline token never leaves the server via serialization. */
    protected $hidden = [
        'credentials',
    ];

    protected $attributes = [
        'status' => self::STATUS_INSTALLED,
    ];

    protected function casts(): array
    {
        return [
            'credentials' => EncryptedJson::class,
            'needs_reauth' => 'boolean',
            'webhook_registration' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isInstalled(): bool
    {
        return $this->status === self::STATUS_INSTALLED;
    }

    /** The decrypted offline access token, or null when uninstalled/never installed. */
    public function accessToken(): ?string
    {
        $token = $this->credentials[self::CRED_ACCESS_TOKEN] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Guarded status move (mirrors Generation/BannerAsset::transitionTo). Only the
     * canonical transitions are legal; anything else throws. Uninstalling wipes the
     * credentials (the token is dead the moment Shopify fires app/uninstalled).
     * Every accepted move writes a semantic activity event.
     *
     * @param  array<string,mixed>  $details
     */
    public function transitionTo(string $next, array $details = []): void
    {
        $current = $this->status ?? self::STATUS_INSTALLED;

        if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_TRANSITION_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->status = $next;

        if ($next === self::STATUS_UNINSTALLED) {
            $this->credentials = null;
            $this->uninstalled_at = now();
        } else {
            $this->installed_at = now();
            $this->needs_reauth = false;
        }

        $this->save();

        app(ActivityRecorder::class)->record(
            kind: $next === self::STATUS_INSTALLED
                ? ActivityEvent::KIND_SHOPIFY_INSTALLED
                : ActivityEvent::KIND_SHOPIFY_UNINSTALLED,
            subject: $this,
            details: ['from' => $current, 'to' => $next, 'shop_domain' => $this->shop_domain] + $details,
            siteId: $this->site_id,
        );
    }
}
