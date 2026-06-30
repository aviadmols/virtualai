<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-site "store type" (StoreCategory): jewelry / clothing / furniture / … . It feeds
 * the product_type leg of the AI prompt resolver so each store gets a tailored try-on
 * prompt. Nullable — an unset site falls back to the scanned product_type, then the
 * generic global prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('product_category')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('product_category');
        });
    }
};
