<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_assets — ONE AI image transform of ONE product image (a packshot, or the product
 * rendered on a model). The BannerAsset shape: a money-path row carrying the SAME guarded
 * status machine and the SAME deterministic idempotency_key (UNIQUE) that its single charge
 * row in credit_ledger carries, so an asset is charged EXACTLY once.
 *
 * Tenant-owned (account_id NOT NULL + BelongsToAccount) + site-scoped + product-scoped.
 *
 * Two independent machines live on this row:
 *  - `status`        — the GENERATION machine: pending -> processing -> succeeded|failed|cancelled.
 *  - `review_status` — the MERCHANT machine:  awaiting_review -> approved | rejected (and back).
 *    Review only exists once the image exists; a REJECT after a successful generation does NOT
 *    refund — the AI ran and the provider charged us (the UI states this plainly).
 *
 * ASYNC provider lifecycle: `provider` + `provider_request_id` + `provider_meta` hold the
 * provider's queue ticket, so a network blip re-polls the SAME submitted request instead of
 * re-submitting (which would double-generate and double-spend upstream). `poll_attempts`
 * bounds the poll budget; exhausting it is a terminal failure with NO charge.
 *
 * `reserved_micro_usd` is the exact amount the in-flight reservation holds, so the poller can
 * rebuild the Reservation to RENEW its TTL (a long render must never let the hold lapse) and
 * to release it on any terminal path.
 *
 * Push fields (`push_status`, `shopify_media_id`, ...) are RESERVED for Phase 5 (push approved
 * images to Shopify product media). Phase 4 writes only the default `not_pushed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_assets', function (Blueprint $table): void {
            $table->id();

            // Tenancy + scope — carried explicitly into every job, never inferred.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('product_image_batches')->cascadeOnDelete();

            // The DB-managed AI operation that produced it (packshot_generation | on_model_generation).
            $table->string('operation_key', 64);

            // pending | processing | succeeded | failed | cancelled. Guarded transitions.
            $table->string('status', 16)->default('pending');

            // awaiting_review | approved | rejected. Guarded; only meaningful once succeeded.
            $table->string('review_status', 20)->default('awaiting_review');

            // The stable per-request id (the last segment of the idempotency key). A batch run
            // uses the constant 'batch' — so re-running the same selection cannot regenerate or
            // re-charge the same image. An explicit "Regenerate" mints a fresh id ON PURPOSE.
            $table->string('client_request_id', 80);

            // The deterministic asset key:
            //   product_asset:{account}:{site}:{sha1(product,source_hash,op,prompt_version,model,params)}:{crq}
            // UNIQUE, so a double-clicked batch collapses to ONE asset (and one possible charge).
            $table->string('idempotency_key');

            // The chosen SOURCE product image + its hash (a key segment: a different source
            // image is a different asset).
            $table->string('source_image_url', 2048);
            $table->string('source_image_hash', 64);

            // The generated result (PRIVATE object; the panel receives a short-lived signed URL).
            $table->string('image_path')->nullable();
            $table->string('image_mime', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            // What ran: the resolved + actually-used model, and the upstream that served it.
            $table->string('model_used')->nullable();
            $table->string('provider', 24)->nullable();

            // The provider's queue ticket — the anti-double-submit anchor. A retry of the submit
            // job with this set NEVER re-submits; it hands off to the poller.
            $table->string('provider_request_id')->nullable();
            $table->json('provider_meta')->nullable();

            // Bounded poll budget (exhausted -> terminal failed, no charge).
            $table->unsignedInteger('poll_attempts')->default(0);

            // The exact micro-USD the in-flight reservation holds (renewed on each poll tick).
            $table->bigInteger('reserved_micro_usd')->default(0);

            // The honest cost behind the charge + the charged selling value (micro-USD, integers).
            $table->bigInteger('actual_cost_micro_usd')->nullable();
            $table->bigInteger('charge_micro_usd')->nullable();

            // The single charge ledger row (null until charged). 1:1 with credit_ledger.
            $table->unsignedBigInteger('charge_ledger_id')->nullable();

            // On failure: the classified GenerationFailureCode. Null on success.
            $table->string('failure_code', 40)->nullable();

            // Prompt snapshot / failure message / provider generation id. JSON so it can grow.
            $table->json('meta')->nullable();

            // --- RESERVED for Phase 5 (push to Shopify product media). Untouched in Phase 4. ---
            $table->string('push_status', 16)->default('not_pushed');
            $table->string('shopify_media_id')->nullable();
            $table->text('push_error')->nullable();
            $table->timestamp('pushed_at')->nullable();

            $table->timestamps();

            // A double charge is impossible at the DB level — the deterministic key collides.
            $table->unique('idempotency_key');

            // The review grid (per site, filtered by review state, newest first) and the batch
            // progress read (per batch, filtered by status).
            $table->index(['account_id', 'site_id', 'review_status', 'created_at']);
            $table->index(['account_id', 'batch_id', 'status']);
            $table->index(['account_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_assets');
    }
};
