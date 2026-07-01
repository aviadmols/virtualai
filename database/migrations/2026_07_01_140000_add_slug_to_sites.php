<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * sites gains a unique `slug` — the shop's URL segment for the merchant panel's Filament
 * tenancy (`/merchant/{slug}/…`). Filament routes tenants globally by slug, so it must be
 * unique across ALL accounts. Existing rows are backfilled (id suffix guarantees uniqueness);
 * new sites generate a slug in Site::booted() beside site_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        foreach (DB::table('sites')->select('id', 'name')->get() as $site) {
            $base = Str::slug((string) $site->name) ?: 'shop';
            DB::table('sites')->where('id', $site->id)->update(['slug' => $base.'-'.$site->id]);
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
