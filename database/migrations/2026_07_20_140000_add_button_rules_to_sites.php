<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sites.button_rules — the merchant's "where does the Try-it-on button appear" rule
 * ({mode, values}), evaluated server-side in the widget bootstrap. Nullable: an absent rule
 * means "show on every confirmed product page" (the fail-open default in ButtonVisibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->json('button_rules')->nullable()->after('widget_appearance');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('button_rules');
        });
    }
};
