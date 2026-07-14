<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * end_users — the lead / "Vsio user". Tenant-owned: account_id NOT NULL +
 * BelongsToAccount; site-scoped (site_id) because the free-tries limit and the
 * lead funnel live per Site.
 *
 * anon_token is the anonymous browser token; the free-tries counter is tracked
 * per (site_id, anon_token) so it survives navigation between PDPs — hence the
 * UNIQUE (site_id, anon_token). registered_at is null until signup; the lead
 * funnel status is forward-only (new -> generated -> added_to_cart -> purchased,
 * or any -> incomplete) via a guarded transition on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('end_users', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary (carried explicitly, never inferred).
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            // Sub-scope within the account.
            $table->foreignId('site_id')
                ->constrained('sites')->cascadeOnDelete();

            // Anonymous browser token. The free-tries counter follows it across
            // PDPs, so it is unique within a site.
            $table->string('anon_token', 64);

            // Captured at signup (null until then).
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Lead funnel: new | generated | added_to_cart | purchased | incomplete.
            $table->string('status', 16)->default('new');

            // The counter the LeadGate reads against free_generations_before_signup.
            $table->unsignedInteger('generations_used')->default(0);

            // Null until the end user registers; set on signup capture.
            $table->timestamp('registered_at')->nullable();

            // Acquisition / campaign attribution.
            $table->string('source')->nullable();
            $table->json('utm')->nullable();

            // Touched on each widget interaction (lead recency).
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // The free-tries counter is keyed here — one lead row per browser per site.
            $table->unique(['site_id', 'anon_token']);

            // Hot paths: a per-account/site lead list and a funnel-status filter.
            $table->index(['account_id', 'site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('end_users');
    }
};
