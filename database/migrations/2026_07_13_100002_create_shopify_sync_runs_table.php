<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_sync_runs — one catalog/selection import, as a DURABLE, RESUMABLE row.
 *
 * Tenant-owned (account_id NOT NULL + BelongsToAccount) + site-scoped. The row is the
 * source of truth for a paginated import: `cursor` holds Shopify's endCursor, so a job
 * killed mid-catalog (throttle, deploy, worker restart) resumes EXACTLY where it stopped
 * instead of re-walking (and re-billing nothing — sync itself is free, but a re-walk is
 * a throttle burn) the whole store.
 *
 * The counters drive the merchant's live progress UI; `correlation_id` ties every log
 * line of the run together (the Phase-1 observability contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_runs', function (Blueprint $table): void {
            $table->id();

            // Tenancy — the isolation boundary.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            // catalog (walk every product) | selection (an explicit GID list) | webhook.
            $table->string('mode', 16);

            // pending -> running -> completed | failed  (guarded on the model).
            $table->string('status', 16)->default('pending');

            // Shopify's endCursor for the NEXT page — the resume point. NULL = start.
            $table->string('cursor')->nullable();

            // The explicit GIDs a `selection` run imports (null for a catalog walk).
            $table->json('requested_gids')->nullable();

            // Progress counters (the merchant's live UI reads these).
            $table->unsignedInteger('total_seen')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('archived')->default(0);
            $table->unsignedInteger('failed')->default(0);

            // How many pages the run has walked (bounds a runaway self-redispatch).
            $table->unsignedInteger('pages')->default(0);

            // The one id that ties every log line of this run together.
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
        Schema::dropIfExists('shopify_sync_runs');
    }
};
