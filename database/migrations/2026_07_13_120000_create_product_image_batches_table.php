<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_image_batches — one merchant-triggered BULK AI image run (packshots /
 * product-on-model), as a durable row.
 *
 * Tenant-owned (account_id NOT NULL + BelongsToAccount) + site-scoped. The row — never
 * the queue — is the source of truth for progress: the counters are what the merchant's
 * live progress bar reads, so a worker restart / deploy cannot lose the picture.
 *
 * The estimate columns are ADVISORY only (the pre-flight "this batch will cost about X"
 * shown before the merchant confirms). They never authorise a charge: the authoritative
 * money path is per asset — CreditGate -> reserve -> provider -> charge ONLY on success.
 *
 * `correlation_id` ties every log line of the batch together (the Phase-1 observability
 * contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_batches', function (Blueprint $table): void {
            $table->id();

            // Tenancy — the isolation boundary; carried explicitly into every job.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            // Which DB-managed AI operation this batch runs (packshot_generation |
            // on_model_generation). The model/prompt behind it is resolved per asset by
            // AiOperationResolver — never hardcoded at a call site.
            $table->string('operation_key', 64);

            // Which product image feeds the transform (main | alt_1 | alt_2 | alt_3).
            $table->string('source_pick', 16);

            // pending -> running -> completed | failed   (guarded on the model).
            $table->string('status', 16)->default('pending');

            // Progress counters — the merchant's live UI reads exactly these.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);

            // ADVISORY pre-flight estimate (integer micro-USD of SELLING value): what one
            // asset is expected to cost and what the whole batch is expected to cost. Shown
            // before the run; never used to charge.
            $table->bigInteger('estimate_per_asset_micro_usd')->default(0);
            $table->bigInteger('estimate_micro_usd')->default(0);

            // What the batch has ACTUALLY been charged so far (sum of the per-asset charge
            // rows). A display convenience — credit_ledger stays the money truth.
            $table->bigInteger('charged_micro_usd')->default(0);

            // The one id that ties every log line of this batch together.
            $table->string('correlation_id', 64)->nullable();

            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_batches');
    }
};
