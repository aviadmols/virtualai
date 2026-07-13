<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sites.platform — which storefront platform a site runs on ('custom' = the scripted
 * widget install; 'shopify' = connected via the Shopify app). Drives platform-specific
 * behavior: product-data source, widget add-to-cart strategy, and credit-purchase rail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('platform')->default('custom')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
