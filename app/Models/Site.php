<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Domain\Tenancy\MerchantSiteTenancy;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Site — the sub-scope within an account. Tenant-owned (BelongsToAccount).
 *
 * Carries the public site_key and the encrypted widget_secret. Both are
 * generated on creation. The widget authenticates by site_key + Origin
 * allow-list; the widget_secret is server-side only and never sent to the
 * browser (it is even hidden from array/JSON serialization here).
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // Public key sent by the widget in the browser. Random URL-safe token.
    private const SITE_KEY_PREFIX = 'site_';

    private const SITE_KEY_RANDOM_BYTES = 24;

    // Server-side HMAC secret, never sent to the browser.
    private const WIDGET_SECRET_RANDOM_BYTES = 32;

    // Random suffix length for the globally-unique tenant slug.
    private const SLUG_SUFFIX_LEN = 6;

    // The Filament merchant-panel tenant slug attribute/route field.
    public const TENANT_SLUG_FIELD = 'slug';

    public const DEFAULT_FREE_GENERATIONS_BEFORE_SIGNUP = 2;

    public const DEFAULT_RETENTION_DAYS = 30;

    // Retention windows the merchant may choose (days). NULL is the "until manual
    // delete" sentinel — no auto-purge window; media is kept until the merchant
    // deletes it. The RetentionPurgeJob (Phase 9) skips a site whose window is null.
    public const RETENTION_DAYS_ALLOWED = [7, 30, 90];

    public const RETENTION_UNTIL_DELETE = null;

    protected $fillable = [
        'name',
        'domain',
        'product_category',
        'allowed_origins',
        'site_key',
        'widget_secret',
        'selectors',
        'ai_model',
        'prompts',
        'gallery_settings',
        'widget_appearance',
        'club_config',
        'usage_limits',
        'post_signup_grant',
        'privacy_config',
        'free_generations_before_signup',
        'retention_days',
    ];

    /** widget_secret never leaves the server via serialization. */
    protected $hidden = [
        'widget_secret',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'widget_secret' => EncryptedString::class,
            'selectors' => 'array',
            'prompts' => 'array',
            'gallery_settings' => 'array',
            'widget_appearance' => 'array',
            'club_config' => 'array',
            'usage_limits' => 'array',
            'post_signup_grant' => 'array',
            'privacy_config' => 'array',
            'free_generations_before_signup' => 'integer',
            'retention_days' => 'integer',
            'widget_last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            // Generate per-site credentials if not explicitly provided.
            if ($site->site_key === null) {
                $site->site_key = self::generateSiteKey();
            }

            if ($site->widget_secret === null) {
                $site->widget_secret = self::generateWidgetSecret();
            }

            // The Filament-tenancy URL slug (globally unique). Generated once, like site_key.
            if ($site->slug === null || $site->slug === '') {
                $site->slug = self::generateSlug($site->name);
            }
        });

        // NULL-not-empty guard: an empty string collides under the unique
        // index (Postgres treats '' as a real value; NULL is excluded).
        static::saving(function (Site $site): void {
            if ($site->site_key === '') {
                $site->site_key = null;
            }

            // Auto-allow the site's OWN domain origin so the widget runs there with no
            // manual allowed-origins step. Only when none are set yet (a fresh site with a
            // domain), so explicit/manual origins are never overwritten.
            if (empty($site->allowed_origins)) {
                $origin = self::originFromDomain($site->domain);
                if ($origin !== null) {
                    $site->allowed_origins = [$origin];
                }
            }
        });

        // Deleting a site cascades its DB rows (FK cascadeOnDelete); purge its bucket media
        // too so nothing is orphaned. Fires on `deleted` (the row is gone) with the explicit
        // account_id + site_id — never inferred on the worker. Queued on the media queue so a
        // large bucket delete never blocks the delete request.
        static::deleted(function (Site $site): void {
            \App\Domain\Media\PurgeSiteMediaJob::dispatch((int) $site->account_id, (int) $site->getKey());
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Route-model binding. The Filament TENANT segment resolves by `slug` and must read across
     * accounts (a merchant's own shop resolves before the account is bound; a super-admin drills
     * into any shop) — routed through the audited MerchantSiteTenancy seam and gated IMMEDIATELY
     * by User::canAccessTenant. All OTHER bindings (records bound by id) stay account-scoped.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === self::TENANT_SLUG_FIELD) {
            return MerchantSiteTenancy::resolveBySlug((string) $value);
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * The origin (scheme://host[:port]) of a domain/URL — what the widget's allowed-origin
     * check compares against. Returns null for a blank/host-less value.
     */
    public static function originFromDomain(?string $domain): ?string
    {
        $domain = trim((string) $domain);

        if ($domain === '') {
            return null;
        }

        // parse_url needs a scheme to find the host reliably; assume https if absent.
        $parts = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain);

        if (empty($parts['host'])) {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'];

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    /** A fresh public site_key (URL-safe). */
    public static function generateSiteKey(): string
    {
        return self::SITE_KEY_PREFIX.Str::random(self::SITE_KEY_RANDOM_BYTES);
    }

    /** A globally-unique URL slug for the merchant-panel tenant segment (name + random suffix). */
    public static function generateSlug(?string $name): string
    {
        $base = Str::slug((string) $name);

        return ($base !== '' ? $base : 'shop').'-'.Str::lower(Str::random(self::SLUG_SUFFIX_LEN));
    }

    /** A fresh server-side widget secret (hex). */
    public static function generateWidgetSecret(): string
    {
        return bin2hex(random_bytes(self::WIDGET_SECRET_RANDOM_BYTES));
    }
}
