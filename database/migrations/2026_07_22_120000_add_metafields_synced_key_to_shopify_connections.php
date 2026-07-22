<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The convergence marker for the shop-metafield sync (SyncShopMetafieldsJob): the site_key
 * value LAST written to the shop's app-owned metafield. The job skips the API entirely when
 * the stored value already equals the site's current key, and re-syncs after a key rotation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_connections', function (Blueprint $table): void {
            $table->string('metafields_synced_key')->nullable()->after('webhook_registration');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_connections', function (Blueprint $table): void {
            $table->dropColumn('metafields_synced_key');
        });
    }
};
