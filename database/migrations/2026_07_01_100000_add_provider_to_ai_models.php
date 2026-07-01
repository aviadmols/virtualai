<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_models gains a `provider` (openrouter|byteplus) so the resolver/caller can route each
 * model to the right upstream. Every existing row defaults to 'openrouter' — behaviour
 * unchanged for the current OpenRouter-only catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->string('provider')->default('openrouter')->after('operation_key')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
