<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * banner_events — append-only per-banner analytics (impression | click). Kept OUT of the
 * shared activity_events funnel because impressions are high-frequency; a dedicated,
 * banner-indexed table gives exact per-banner click counts + impressions -> CTR without
 * bloating the timeline. Tenant-owned (account_id + BelongsToAccount), site-scoped.
 *
 * anon_token is stored only to let the widget de-dupe an impression per shopper-session
 * client-side; no other PII. created_at only (append-only, no updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();

            // impression | click.
            $table->string('kind', 16);

            // The page path the event happened on (query/fragment stripped upstream).
            $table->string('path')->nullable();

            // The anonymous shopper token (session dedupe only; no other PII).
            $table->string('anon_token', 80)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Exact per-banner counts by kind within a window (clicks/impressions -> CTR).
            $table->index(['account_id', 'banner_id', 'kind', 'created_at']);
            $table->index(['site_id', 'banner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_events');
    }
};
