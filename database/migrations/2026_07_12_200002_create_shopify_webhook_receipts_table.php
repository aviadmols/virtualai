<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_webhook_receipts — the durable inbound-webhook inbox (PLATFORM-level, no
 * account_id: rows are created PRE-BIND, before the tenant is known — the same
 * documented exception class as the SiteRouter lookup).
 *
 * `webhook_id` (Shopify's X-Shopify-Webhook-Id) is the dedupe wall. The state machine
 * (received → queued → processing → processed | failed) makes the ROW the source of
 * truth for "was this webhook actually handled" — returning 200 to Shopify and losing
 * the queued job is recoverable, because the recovery sweep re-dispatches anything
 * stuck in received/queued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_webhook_receipts', function (Blueprint $table) {
            $table->id();

            // Shopify's delivery id — the idempotency/dedupe key.
            $table->string('webhook_id')->unique();

            $table->string('topic')->index();
            $table->string('shop_domain')->index();

            // received | queued | processing | processed | failed.
            $table->string('status')->default('received')->index();

            // The raw webhook body (pruned after the retention window).
            $table->json('payload')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('correlation_id')->index();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_receipts');
    }
};
