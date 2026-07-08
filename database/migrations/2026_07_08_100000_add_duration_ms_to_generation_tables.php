<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the provider RENDER time (milliseconds) of each generation, measured in the job and
 * written on success — the source for the Super-Admin try-on timing graph. Nullable: historical
 * rows + failures carry no duration (the report falls back to a coarse derived time for those).
 */
return new class extends Migration
{
    private const TABLES = ['generations', 'banner_assets'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedInteger('duration_ms')->nullable()->after('actual_cost_micro_usd');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('duration_ms');
            });
        }
    }
};
