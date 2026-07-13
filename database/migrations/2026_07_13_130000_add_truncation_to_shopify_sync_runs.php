<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_sync_runs.truncated — was this walk CUT SHORT before it saw the whole catalog?
 *
 * The distinction is load-bearing, not cosmetic. The "archive everything the walk did not
 * return" sweep rests on ONE premise: the walk saw the whole store. A walk stopped by the
 * page budget did NOT, so its silence about a product means nothing — sweeping there would
 * archive live products and drop them out of the widget. A truncated run therefore
 * completes WITHOUT sweeping, and says so: on the row (here), on the timeline, and in the
 * merchant's import history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_sync_runs', function (Blueprint $table): void {
            // True = the walk hit its budget with pages still unread. Nothing was swept.
            $table->boolean('truncated')->default(false)->after('pages');

            // Why it stopped (ShopifySyncRun::TRUNCATION_*) — never a free-text string.
            $table->string('truncated_reason', 32)->nullable()->after('truncated');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['truncated', 'truncated_reason']);
        });
    }
};
