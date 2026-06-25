<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_operations — one row per AI operation (product_scan, try_on_generation).
 *
 * GLOBAL (non-tenant) control plane: this is a platform catalog, NOT tenant-owned,
 * so it lives on GlobalModels::ALLOW_LIST and is NOT BelongsToAccount. Super-Admin
 * edits these from the DB without a redeploy; nothing AI is hardcoded in a service.
 *
 * Holds the per-operation defaults the resolver reads: default + fallback model,
 * image quality, aspect ratio, sampler params (seed/temperature/...), retention,
 * estimated cost, and credit_multiplier (nullable; overrides the global MARKUP when
 * set — read by the Phase-5 billing layer, never applied here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();

            // Stable operation key the resolver is called with (unique).
            $table->string('operation_key')->unique();

            // Human label for the admin control plane.
            $table->string('label')->nullable();

            // Default + fallback OpenRouter model ids. Nullable: the resolver may
            // fall back to the ai_models default/fallback flag when these are null.
            $table->string('default_model')->nullable();
            $table->string('fallback_model')->nullable();

            // Image-modality knobs (try_on). Config, never a service literal.
            $table->string('image_quality')->nullable();   // e.g. standard | high
            $table->string('aspect_ratio')->nullable();     // e.g. 1:1 | 3:4

            // Sampler / call params bag: seed, temperature, top_p, max_tokens, ...
            // Determinism lives here, not in code.
            $table->json('params')->nullable();

            // Shape of the strict JSON the operation extracts (product_scan).
            $table->json('input_schema')->nullable();

            // Retention window (days) for media produced by this operation.
            $table->unsignedSmallInteger('retention_days')->nullable();

            // Estimated USD cost — the gate's reservation estimate + the
            // cost-unavailable fallback. Stored as micro-USD (integer, never float).
            $table->unsignedBigInteger('estimated_cost_micro_usd')->nullable();

            // Per-operation markup override; NULL means use the global MARKUP.
            // Phase-5 billing reads this; this layer only returns it in the bag.
            $table->decimal('credit_multiplier', 6, 3)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operations');
    }
};
