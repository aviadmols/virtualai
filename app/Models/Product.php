<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
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
    /** @use HasFactory<\Database\Factories\ProductFactory> */
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

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal product status transition %s -> %s (product #%s).';

    protected $fillable = [
        'site_id',
        'source_url',
        'source_url_hash',
        'status',
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
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'sale_price_minor' => 'integer',
            'regular_price_minor' => 'integer',
            'price_is_range' => 'boolean',
            'images' => 'array',
            'physical_dimensions' => 'array',
            'field_confidence' => 'array',
            'detected_selectors' => 'array',
            'scan_raw' => 'array',
            'warnings' => 'array',
            'confidence' => 'float',
            'confirmed_at' => 'datetime',
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
