<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generations — one try-on attempt. The ONLY place a credit is charged.
 *
 * Tenant-owned: account_id NOT NULL + BelongsToAccount; site-scoped (site_id), with
 * FKs to the end_user (the lead), the product, and the selected product_variant. The
 * status machine is guarded on the model (pending -> processing -> succeeded | failed,
 * pending|processing -> cancelled); every move writes an activity_event.
 *
 * idempotency_key is the deterministic generation key
 * generation:{account}:{site}:{end_user}:{product}:{sha1(variant)}:{client_request_id}
 * — UNIQUE here so a double-clicked / double-dispatched generation collapses to one
 * row (the four-layer wall: ShouldBeUnique + row lock + ledger pre-check + this index
 * + the client_request_id segment). The matching charge row in credit_ledger carries
 * the SAME key, so charging is idempotent end-to-end.
 *
 * charge_ledger_id links the succeeded generation to its single charge row. source/
 * result image paths are opaque media-disk keys (never public URLs); retention metadata
 * lets the Phase-9 purge job strip the bytes + PII while KEEPING the ledger row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary (carried explicitly into the job, never inferred).
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            // Sub-scope + linkage. A generation is for one lead, on one product, of one variant.
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('end_user_id')->constrained('end_users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // The selected variant may be deleted on a re-scan; keep the generation (snapshot in meta).
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            // pending | processing | succeeded | failed | cancelled. Guarded transitions.
            $table->string('status', 16)->default('pending');

            // The widget's stable per-click id — the last segment of the idempotency key.
            $table->string('client_request_id', 80);

            // The deterministic generation key (ARCHITECTURE.md). UNIQUE: a double
            // dispatch / double-click for the same (account,site,end_user,product,variant,
            // client_request_id) collapses to one row. The key embeds account_id, so the
            // GLOBAL unique cannot cross-collide between accounts (see credit_ledger N1).
            $table->string('idempotency_key');

            // Opaque media-disk keys (NOT public URLs). source = the shopper photo (input),
            // result = the generated try-on (output). Both are purged by retention; the
            // ledger row is kept.
            $table->string('source_image_path')->nullable();
            $table->string('result_image_path')->nullable();

            // What ran: the model OpenRouter actually used (may be the fallback).
            $table->string('model_used')->nullable();

            // The real OpenRouter cost behind the charge (integer micro-USD). Null until success.
            $table->bigInteger('actual_cost_micro_usd')->nullable();

            // The single charge ledger row id (null until charged). Links money <-> generation.
            $table->unsignedBigInteger('charge_ledger_id')->nullable();

            // On failure: the classified OpenRouterException code (or gate reason). Null on success.
            $table->string('failure_code', 40)->nullable();

            // Height + optional body/age/gender/angle + the variant snapshot + the prompt
            // snapshot + retention metadata. JSON so the shape can grow without a migration.
            $table->json('meta')->nullable();

            $table->timestamps();

            // A double charge is impossible at the DB level — the deterministic key collides.
            $table->unique('idempotency_key');

            // Hot paths: a per-account/site generation list (timeline, gallery) filtered
            // by status and ordered by recency — status is in the index so a status-filtered
            // list (e.g. only succeeded for the gallery) is index-covered — and a per-lead
            // lookup (the shopper's session gallery).
            $table->index(['account_id', 'site_id', 'status', 'created_at']);
            $table->index(['end_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
