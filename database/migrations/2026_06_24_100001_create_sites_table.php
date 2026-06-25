<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sites — the sub-scope within an account. account_id NOT NULL + indexed;
 * isolation is enforced by account_id (BelongsToAccount). Carries the public
 * site_key and the encrypted widget_secret. Config columns (selectors, model,
 * prompts, gallery, retention, lead-gate) are STUBBED here — Phase 2 declares
 * them; their behaviour lands in later phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            // Tenancy. account_id NOT NULL + FK + index — isolation boundary.
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            $table->string('name');
            $table->string('domain')->nullable();

            // Allow-listed origins the widget Origin header is checked against.
            $table->json('allowed_origins')->nullable();

            // Public widget key (sent in the browser). Unique, indexed.
            // PERSIST NULL, NEVER '' — empty string collides under a unique
            // index (see model guard); NULL is excluded from the constraint.
            $table->string('site_key')->nullable()->unique();

            // Server-side HMAC secret, encrypted at rest with the dedicated
            // TENANT_CREDENTIALS_KEY cipher. Never sent to the browser.
            $table->text('widget_secret')->nullable();

            // --- Stubbed config columns (behaviour lands in later phases) ---
            $table->json('selectors')->nullable();             // page selector overrides
            $table->string('ai_model')->nullable();            // per-site model override
            $table->json('prompts')->nullable();               // per-site prompt overrides
            $table->json('gallery_settings')->nullable();      // session gallery config
            $table->json('usage_limits')->nullable();          // per-site caps (billing)
            $table->json('post_signup_grant')->nullable();     // post-signup free-tries grant
            $table->json('privacy_config')->nullable();        // consent / GDPR copy + toggles

            // Lead gate: default 2 free generations before signup.
            $table->unsignedSmallInteger('free_generations_before_signup')->default(2);

            // Media retention window (days); drives the future RetentionPurgeJob.
            $table->unsignedSmallInteger('retention_days')->default(30);

            $table->timestamps();

            // Hot-path composite: a site lookup within an account.
            $table->index(['account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
