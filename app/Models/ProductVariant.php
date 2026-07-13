<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductVariant — one selectable variant of a Product. Tenant-owned
 * (BelongsToAccount) + belongs to a product.
 *
 * options is a {axis => value} map; the control type + per-value selector hints
 * live on the Product's detected_selectors so the widget can drive selection.
 *
 * `external_id` (a Shopify variant GID) is the upsert key for a synced product: a
 * re-sync UPDATES this row rather than replacing it, so the id every past Generation
 * references stays valid. A variant that disappears is ARCHIVED, never deleted.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'product_id',
        'external_id',
        'options',
        'position',
        'price_minor',
        'image_url',
        'sku',
        'available',
        'is_active',
        'confidence',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'position' => 'integer',
            'price_minor' => 'integer',
            'available' => 'boolean',
            'is_active' => 'boolean',
            'confidence' => 'float',
            'archived_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Only ACTIVE variants are offered for new generations. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Archive: this variant is gone from the platform's payload. NEVER a hard delete —
     * `generations.product_variant_id` points here, so deleting would orphan the FK and
     * erase try-on history the merchant already paid credits for. Idempotent.
     */
    public function archive(): self
    {
        if (! $this->is_active) {
            return $this;
        }

        $this->is_active = false;
        $this->archived_at = $this->freshTimestamp();
        $this->save();

        return $this;
    }
}
