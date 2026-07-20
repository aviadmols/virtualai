<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen products.fetched_via from varchar(16) to varchar(32).
 *
 * The Shopify import rail stamps fetched_via = 'shopify_admin_api' (17 chars), which
 * overflowed the original varchar(16) and made EVERY Shopify product insert fail with
 * SQLSTATE[22001] "value too long" — so a store import persisted nothing. Postgres treats
 * a varchar length INCREASE as metadata-only (no table rewrite), so this is instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('fetched_via', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('fetched_via', 16)->nullable()->change();
        });
    }
};
