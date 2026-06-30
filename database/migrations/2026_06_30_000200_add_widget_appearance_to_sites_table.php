<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site storefront-widget appearance (button placement + label + colors, popup theme +
 * accent). A JSON blob validated by WidgetAppearance; NULL means "use the defaults".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->json('widget_appearance')->nullable()->after('gallery_settings');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('widget_appearance');
        });
    }
};
