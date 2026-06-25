<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the users.account_id -> accounts FK after accounts exists.
 *
 * The indexed column ships in the base users migration; the FK is added here
 * to respect table creation order. SQLite cannot add an FK to an existing
 * table via ALTER, so the FK is skipped there (tests use sqlite); the column +
 * index already enforce the shape, and isolation is enforced at the app layer.
 * Postgres (production) gets the real constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('account_id')
                ->references('id')->on('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });
    }
};
