<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_last_seen_at — a heartbeat set when the storefront widget boots (calls the
 * bootstrap API) for a site. Lets the setup checklist auto-detect that the install
 * snippet is live on the store, instead of an un-checkable note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('widget_last_seen_at')->nullable()->after('widget_appearance');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('widget_last_seen_at');
        });
    }
};
