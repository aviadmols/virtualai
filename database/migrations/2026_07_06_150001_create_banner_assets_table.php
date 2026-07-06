<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * banner_assets — one AI generation ATTEMPT for a banner (the "chat" iterations). Mirrors
 * the generations money-path row: the SAME guarded status machine, the SAME deterministic
 * idempotency_key (UNIQUE) that the single charge row in credit_ledger carries, so a banner
 * generation is charged exactly once. A merchant regenerates (each = a new asset/candidate)
 * and SELECTS one; the chosen asset's artwork is copied onto banners.image_*.
 *
 * The banner charge references THIS row (credit_ledger.reference_type='banner_asset',
 * reference_id=banner_asset.id). source_image_path is the optional PRIVATE reference upload;
 * image_path is the PUBLIC generated result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_assets', function (Blueprint $table) {
            $table->id();

            // Tenancy — carried explicitly into the job, never inferred.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();

            // pending | processing | succeeded | failed | cancelled. Guarded transitions.
            $table->string('status', 16)->default('pending');

            // The per-generate stable id (the last segment of the idempotency key).
            $table->string('client_request_id', 80);

            // The deterministic banner key: banner:{account}:{site}:{banner}:{client_request_id}.
            // UNIQUE so a double-click collapses to one asset (and one possible charge).
            $table->string('idempotency_key');

            // The merchant's freeform brief (substituted into the admin prompt via strtr).
            $table->text('brief');

            // Optional PRIVATE reference upload (the shopper never receives it).
            $table->string('source_image_path')->nullable();

            // The PUBLIC generated result + its mime/dims (dims feed the widget CLS box).
            $table->string('image_path')->nullable();
            $table->string('image_mime', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            // What ran + the honest cost behind the charge (integer micro-USD, null until success).
            $table->string('model_used')->nullable();
            $table->bigInteger('actual_cost_micro_usd')->nullable();

            // The single charge ledger row id (null until charged).
            $table->unsignedBigInteger('charge_ledger_id')->nullable();

            // On failure: the classified code (GenerationFailureCode). Null on success.
            $table->string('failure_code', 40)->nullable();

            // Prompt snapshot + provider generation id + retention bookkeeping. JSON so it grows.
            $table->json('meta')->nullable();

            $table->timestamps();

            // A double charge is impossible at the DB level — the deterministic key collides.
            $table->unique('idempotency_key');

            // The per-banner candidate list, filtered by status and ordered by recency.
            $table->index(['account_id', 'site_id', 'banner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_assets');
    }
};
