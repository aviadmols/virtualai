<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * product_variants — Shopify Phase 3: the platform's own variant id + the lifecycle.
 *
 * `external_id` (a Shopify ProductVariant GID) is the UPSERT key: a re-sync updates the
 * SAME row, so `generations.product_variant_id` (and every gallery entry pointing at it)
 * survives. It is NULL for a scanned variant and unique per product — NULL, never '',
 * so scanned rows do not collide in the unique index.
 *
 * A variant absent from the platform's payload is ARCHIVED (is_active=false +
 * archived_at) — NEVER hard-deleted. Deleting would orphan the FK from past generations
 * and erase the history a merchant paid credits for.
 *
 * `position` preserves the merchant's own option order from the store.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'product_variants';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('external_id')->nullable()->after('product_id');
            $table->unsignedInteger('position')->default(0)->after('options');

            $table->boolean('is_active')->default(true)->after('available');
            $table->timestamp('archived_at')->nullable()->after('is_active');

            // One row per external variant per product (NULLs excluded → scans unaffected).
            $table->unique(['product_id', 'external_id'], 'variants_product_external_unique');
        });

        DB::table(self::TABLE)->update(['is_active' => true]);
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropUnique('variants_product_external_unique');
            $table->dropColumn(['external_id', 'position', 'is_active', 'archived_at']);
        });
    }
};
