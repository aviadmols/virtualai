<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_models gains an optional per-model `base_url` (the provider REGION HOST) so a single
 * BytePlus account can point different models at different regions — e.g. Seedream 4.5 in
 * ap-southeast while another model stays on the eu-west default. Null = use the provider's
 * configured default host (services.byteplus.base_url). Ignored by OpenRouter (single endpoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->string('base_url')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn('base_url');
        });
    }
};
