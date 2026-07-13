<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — PLACEMENT on product_assets.
 *
 * Phase 4 reserved push_status / shopify_media_id / push_error / pushed_at. The merchant does
 * not only decide WHETHER an approved image goes to the store — they decide WHERE:
 *
 *   append      — end of the gallery (the safe default; nothing existing is touched)
 *   position N  — inserted at a specific slot (N = 1 is the main/featured image)
 *   replace     — swaps out a specific existing product image
 *
 * The chosen placement is PERSISTED so a RE-PUSH retries the same intent (the push only —
 * never the AI generation, which is already paid for), and so the timeline can explain what
 * was done to a live storefront.
 *
 * `push_placement` is the mode, `push_position` the requested 1-based slot, and
 * `push_replaced_media_id` the Shopify media that a replace swapped out (kept after the delete
 * so the undo/audit trail knows exactly what left the gallery).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            // append | position | replace. Null until the merchant pushes.
            $table->string('push_placement', 16)->nullable()->after('push_status');

            // The 1-based slot the merchant asked for (only for placement = position).
            $table->unsignedInteger('push_position')->nullable()->after('push_placement');

            // The existing Shopify media a replace swapped out (only for placement = replace).
            $table->string('push_replaced_media_id')->nullable()->after('push_position');

            // The push grid + the undo sweep ("every asset this product pushed").
            $table->index(['account_id', 'product_id', 'push_status']);
        });
    }

    public function down(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'product_id', 'push_status']);
            $table->dropColumn(['push_placement', 'push_position', 'push_replaced_media_id']);
        });
    }
};
