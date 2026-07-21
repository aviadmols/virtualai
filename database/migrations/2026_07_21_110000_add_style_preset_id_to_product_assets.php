<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_assets.style_preset_id — the chosen visual style for this generation (nullable; null =
 * the operation's default look). A plain nullable id (no FK): StylePreset is a global catalog and
 * deleting a preset must NOT touch historical assets, and StylePresetApplier is fail-open on a
 * missing id anyway. The worker reads it to swap the operation's prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('style_preset_id')->nullable()->after('operation_key');
        });
    }

    public function down(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->dropColumn('style_preset_id');
        });
    }
};
