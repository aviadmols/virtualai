<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site Customer-Club config (Phase 2b). A JSON blob validated by ClubConfig:
 * whether the club is enabled, the member discount percent (display-only), and the
 * per-surface price-zone CSS selectors (pdp/catalog/cart) the widget rewrites. NULL
 * means "use the defaults" (disabled, 0% discount, no zones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->json('club_config')->nullable()->after('widget_appearance');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('club_config');
        });
    }
};
