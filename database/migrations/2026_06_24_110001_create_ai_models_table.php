<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_models — the catalog of allowed OpenRouter model ids per operation.
 *
 * GLOBAL (non-tenant) control plane: a platform catalog on
 * GlobalModels::ALLOW_LIST, NOT BelongsToAccount. The resolver uses the
 * is_default / is_fallback flags as the floor when an operation row leaves its
 * default_model / fallback_model null, and as the allow-list a per-site model
 * override is validated against (so a tenant cannot point at an unlisted model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();

            // The operation this catalog entry belongs to.
            $table->string('operation_key')->index();

            // The exact OpenRouter model id, e.g. google/gemini-2.5-flash.
            $table->string('model_id');

            // Human label for the admin control plane.
            $table->string('label')->nullable();

            // Exactly one default + one fallback per operation (enforced in seed).
            $table->boolean('is_default')->default(false);
            $table->boolean('is_fallback')->default(false);

            // Cost hint for the gate's estimate, micro-USD. Per image (try_on) or
            // per 1k tokens (scan); the unit is recorded alongside it.
            $table->unsignedBigInteger('cost_hint_micro_usd')->nullable();
            $table->string('cost_unit')->nullable();   // per_image | per_1k_tokens

            // Whether the model is currently selectable (admin can retire a model).
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // A model id is catalogued once per operation.
            $table->unique(['operation_key', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
