<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Product — a scanned PDP. Tenant-owned (BelongsToAccount) + site-scoped.
 *
 * A scan NEVER auto-approves. A fresh scan persists at STATUS_DRAFT; only the
 * merchant's confirm() moves it to STATUS_CONFIRMED — the single path a product
 * goes live. A scan that cannot complete (bot-block / render-empty / invalid
 * JSON / below the confidence threshold) lands STATUS_FAILED. Transitions are
 * guarded: an illegal move throws rather than silently corrupting state.
 *
 * Prices are stored in integer MINOR units (locale-aware parsing happens in the
 * Map layer); a float price is never persisted.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // The scan status machine. A successful scan reaches DRAFT only; the merchant
    // confirms to CONFIRMED. FAILED is the terminal scan-failure outcome.
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CONFIRMED,
        self::STATUS_FAILED,
    ];

    // Legal transitions. draft can be confirmed or fail; a failed scan can be
    // re-scanned back to draft; confirmed is terminal-best (a re-scan is an
    // explicit, diff-presented action handled above this model).
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_CONFIRMED, self::STATUS_FAILED],
        self::STATUS_FAILED => [self::STATUS_DRAFT],
        self::STATUS_CONFIRMED => [],
    ];

    // Which rail ingested this product. SOURCE_SCAN = the PDP scraper (source_url);
    // SOURCE_SHOPIFY = the Admin API (external_id = the product GID).
    public const SOURCE_SCAN = 'scan';

    public const SOURCE_SHOPIFY = 'shopify';

    public const SOURCES = [self::SOURCE_SCAN, self::SOURCE_SHOPIFY];

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal product status transition %s -> %s (product #%s).';

    protected $fillable = [
        'site_id',
        'source',
        'external_id',
        'external_handle',
        'source_url',
        'source_url_hash',
        'status',
        'is_active',
        'name',
        'description',
        'product_type',
        'price_minor',
        'currency',
        'sale_price_minor',
        'regular_price_minor',
        'price_is_range',
        'main_image_url',
        'images',
        'physical_dimensions',
        'field_confidence',
        'detected_selectors',
        'scan_raw',
        'fetched_via',
        'warnings',
        'confidence',
        'last_synced_at',
    ];

    protected $attributes = [
        'source' => self::SOURCE_SCAN,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'sale_price_minor' => 'integer',
            'regular_price_minor' => 'integer',
            'price_is_range' => 'boolean',
            'is_active' => 'boolean',
            'images' => 'array',
            'physical_dimensions' => 'array',
            'field_confidence' => 'array',
            'detected_selectors' => 'array',
            'scan_raw' => 'array',
            'warnings' => 'array',
            'confidence' => 'float',
            'confirmed_at' => 'datetime',
            'archived_at' => 'datetime',
            'last_synced_at' => 'datetime',
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

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** The variants still offered for NEW generations (archived ones stay for history). */
    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true)->orderBy('position');
    }

    /** True while the scan still needs merchant review (not live). */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** True once the merchant confirmed — the only state the widget may use. */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /** True when the product came from a connected Shopify store (not a PDP scan). */
    public function isShopify(): bool
    {
        return $this->source === self::SOURCE_SHOPIFY;
    }

    /** Only ACTIVE products are offered for new generations. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Archive: the product vanished from the platform catalog (deleted/unpublished).
     * It is NEVER deleted — generations, ledger rows and the gallery reference it and
     * the merchant's paid history must survive a catalog change. The status machine is
     * untouched: a CONFIRMED product stays confirmed, just inactive.
     */
    public function archive(): self
    {
        if (! $this->is_active) {
            return $this; // idempotent — a replayed delete webhook changes nothing
        }

        $this->is_active = false;
        $this->archived_at = $this->freshTimestamp();
        $this->save();

        return $this;
    }

    /** Un-archive: the product reappeared in the platform catalog. */
    public function restore(): self
    {
        $this->is_active = true;
        $this->archived_at = null;
        $this->save();

        return $this;
    }

    /**
     * The merchant confirm — the ONLY path a product goes live (draft -> confirmed).
     * Optionally persists the merchant's corrected fields/selectors first, so the
     * confirmed snapshot is exactly what the widget will use. Guarded: a non-draft
     * product cannot be confirmed.
     *
     * @param  array<string,mixed>  $corrections  field/selector overrides to apply
     */
    public function confirm(array $corrections = []): self
    {
        if ($corrections !== []) {
            $this->fill($corrections);
        }

        $this->transitionTo(self::STATUS_CONFIRMED);
        $this->confirmed_at = $this->freshTimestamp();
        $this->save();

        return $this;
    }

    /** Mark a scan failed (bot-block / render-empty / invalid / below threshold). */
    public function markFailed(): self
    {
        $this->transitionTo(self::STATUS_FAILED);
        $this->save();

        return $this;
    }

    /**
     * Guarded status move. Only canonical transitions (TRANSITIONS) are legal;
     * anything else throws so an invalid state can never be persisted.
     */
    public function transitionTo(string $next): void
    {
        $current = $this->status ?? self::STATUS_DRAFT;

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($next, $allowed, true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_TRANSITION_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->status = $next;
    }
}
