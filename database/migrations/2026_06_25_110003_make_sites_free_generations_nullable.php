<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lead gate (ARCHITECTURE.md "The lead gate") locks three meanings for
 * sites.free_generations_before_signup:
 *   N    -> N free tries before signup,
 *   0    -> signup required before the first try,
 *   null -> signup NEVER required.
 *
 * The Phase-2 column was NOT NULL (default 2), so the `null` meaning could not be
 * persisted. This makes the column nullable so the gate can express "no signup
 * ever". The default stays 2 (the common case); existing rows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('free_generations_before_signup')
                ->default(2)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('free_generations_before_signup')
                ->default(2)
                ->nullable(false)
                ->change();
        });
    }
};
