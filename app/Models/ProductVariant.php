<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductVariant — one selectable variant of a Product. Tenant-owned
 * (BelongsToAccount) + belongs to a product.
 *
 * options is a {axis => value} map; the control type + per-value selector hints
 * live on the Product's detected_selectors so the widget can drive selection.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'product_id',
        'options',
        'price_minor',
        'image_url',
        'sku',
        'available',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price_minor' => 'integer',
            'available' => 'boolean',
            'confidence' => 'float',
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
}
