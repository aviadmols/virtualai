<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention (ARCHITECTURE.md) lets a merchant keep media for 7/30/90 days OR "until
 * manual delete". The Phase-2 column was NOT NULL (default 30), so the until-delete
 * meaning could not be persisted.
 *
 * This makes sites.retention_days nullable, where NULL is the "until manual delete"
 * sentinel (Site::RETENTION_UNTIL_DELETE) — no auto-purge window. The default stays 30
 * (the common case); existing rows are unchanged. The RetentionPurgeJob (Phase 9) skips
 * a site whose window is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('retention_days')
                ->default(30)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('retention_days')
                ->default(30)
                ->nullable(false)
                ->change();
        });
    }
};
