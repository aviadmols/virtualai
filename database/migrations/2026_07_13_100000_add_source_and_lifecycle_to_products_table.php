<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products — Shopify Phase 3: WHERE a product came from, and its LIFECYCLE.
 *
 * `source` splits the two ingestion rails (scan = the PDP scraper, shopify = the Admin
 * API). `external_id` is the platform's own id (a Shopify product GID) and is UNIQUE
 * PER SITE — a store may only ever hold one row per Shopify product. It is nullable and
 * stored as NULL (never ''), because '' is a distinct value that COLLIDES in a unique
 * index while NULL is excluded from it (the locked empty-string pitfall).
 *
 * Lifecycle: a product that disappears from Shopify is ARCHIVED (is_active=false +
 * archived_at), never deleted — generations, ledger rows and the gallery all reference
 * it, and the financial/audit history must survive a catalog change.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'products';

    private const SOURCE_SCAN = 'scan';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            // scan | shopify — which rail ingested this product.
            $table->string('source', 16)->default(self::SOURCE_SCAN)->after('site_id');

            // The platform's product id (Shopify GID) + its storefront handle. NULL for
            // a scanned product; a unique (site_id, external_id) pair for an imported one.
            $table->string('external_id')->nullable()->after('source');
            $table->string('external_handle')->nullable()->after('external_id');

            // Lifecycle: archived (absent from the platform catalog) products stay for
            // history but stop being offered for NEW generations.
            $table->boolean('is_active')->default(true)->after('status');
            $table->timestamp('archived_at')->nullable()->after('confirmed_at');

            // When the last successful sync/scan refreshed this row's data.
            $table->timestamp('last_synced_at')->nullable()->after('archived_at');

            // One row per external product per site. NULLs are excluded from the index,
            // so every scanned product (external_id NULL) coexists freely.
            $table->unique(['site_id', 'external_id'], 'products_site_external_unique');

            // Hot path: "this site's active products from rail X".
            $table->index(['account_id', 'site_id', 'source', 'is_active'], 'products_account_site_source_idx');
        });

        // Existing rows predate the column: they are all scans, all active.
        DB::table(self::TABLE)->update([
            'source' => self::SOURCE_SCAN,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropUnique('products_site_external_unique');
            $table->dropIndex('products_account_site_source_idx');
            $table->dropColumn([
                'source',
                'external_id',
                'external_handle',
                'is_active',
                'archived_at',
                'last_synced_at',
            ]);
        });
    }
};
