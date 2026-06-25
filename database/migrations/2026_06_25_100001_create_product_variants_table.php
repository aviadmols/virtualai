<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_variants — one selectable variant of a Product (a concrete combination
 * of axis values, e.g. {color: Red, size: M}). Tenant-owned: account_id NOT NULL
 * + BelongsToAccount; belongs to a product.
 *
 * options is a JSON map {axis => value}; the per-axis controls (swatch/dropdown/
 * radio/image-swatch) + the per-value selector hints live on the Product's
 * detected_selectors variations entry so the widget can drive selection at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary (carried explicitly, never inferred).
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')->cascadeOnDelete();

            // {axis => value} for this variant, e.g. {"color":"Red","size":"M"}.
            $table->json('options');

            // Per-variant price (minor units) when it differs from the product;
            // null inherits the product price.
            $table->unsignedBigInteger('price_minor')->nullable();

            // The image this variant swaps to (image-swatch variants), resolved absolute.
            $table->text('image_url')->nullable();

            // SKU when present on the page / JSON-LD offers.
            $table->string('sku')->nullable();

            // Whether the page marked this variant available / in stock.
            $table->boolean('available')->default(true);

            // Confidence for this variant row (the model's certainty it exists).
            $table->decimal('confidence', 4, 3)->nullable();

            $table->timestamps();

            $table->index(['account_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
