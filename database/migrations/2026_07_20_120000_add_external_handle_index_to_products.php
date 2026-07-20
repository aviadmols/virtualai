<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the Shopify widget-resolution key. BootstrapController resolves a Shopify product
 * by the /products/{handle} in the shopper's URL → (site_id, external_handle) on EVERY PDP
 * boot (the exact source_url_hash rarely matches a live storefront URL). Without an index,
 * a large catalog scans all of a site's Shopify rows per boot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['site_id', 'external_handle'], 'products_site_handle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_site_handle_idx');
        });
    }
};
