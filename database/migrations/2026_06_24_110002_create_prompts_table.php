<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * prompts — the per-operation prompt templates, resolved site -> account ->
 * product_type -> global (first match wins; global ALWAYS exists).
 *
 * DUAL-SCOPE NUANCE (documented for the isolation audit):
 *  - scope=global / scope=product_type rows are PLATFORM-GLOBAL (account_id NULL,
 *    site_id NULL) — they live on GlobalModels::ALLOW_LIST and are visible to
 *    every tenant. They carry NO tenant and are seeded by the platform.
 *  - scope=account / scope=site rows are TENANT-OWNED (account_id NOT NULL) and
 *    must never be read cross-account.
 *
 * Because the table mixes global and tenant rows, the model is NOT
 * BelongsToAccount (its fail-closed global scope would also hide the global
 * rows). Isolation for the tenant rows is enforced INSTEAD by a single
 * tenant-aware resolution query (AiOperationResolver) that filters account/site
 * scopes by the bound tenant and reads global/product_type scopes globally.
 * This is the only sanctioned cross-cut and it does NOT open a cross-account
 * read hole: an account/site lookup is always constrained by account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();

            // global | product_type | account | site (resolution precedence).
            $table->string('scope')->index();

            // The operation this prompt belongs to.
            $table->string('operation_key')->index();

            // Only meaningful for scope=product_type.
            $table->string('product_type')->nullable()->index();

            // Tenant ownership for scope=account / scope=site rows. NULL for the
            // platform-global rows (scope=global / scope=product_type). NOT a
            // BelongsToAccount FK auto-fill — set explicitly and resolved
            // tenant-aware (see class docblock).
            $table->foreignId('account_id')->nullable()
                ->constrained('accounts')->cascadeOnDelete();

            // Only meaningful for scope=site.
            $table->foreignId('site_id')->nullable()
                ->constrained('sites')->cascadeOnDelete();

            // The templated text. Placeholders are {{name}} substituted with
            // strtr() at the call site, NEVER Blade::render() (RCE prevention).
            $table->text('system_prompt')->nullable();
            $table->text('user_prompt');

            // Editorial version, snapshotted onto a Generation by laravel-backend.
            $table->unsignedInteger('version')->default(1);

            // Admin can disable a prompt without deleting it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Hot-path lookups for each resolution leg.
            $table->index(['operation_key', 'scope']);
            $table->index(['account_id', 'site_id', 'operation_key']);
        });

        // DETERMINISM CONSTRAINT (S1): one row per resolution-leg tuple per
        // version, so a leg can never carry two competing rows and the resolver's
        // selection is unambiguous. The natural key is
        // (operation_key, scope, product_type, account_id, site_id, version).
        //
        // NULLABLE-COLUMN CHOICE: Postgres (and SQLite) treat NULLs as DISTINCT in
        // a composite unique, which would defeat the intent for the global floor
        // (product_type / account_id / site_id are all NULL there — two such rows
        // would NOT collide). So we normalize the nullables with COALESCE to a
        // sentinel inside an EXPRESSION unique index. COALESCE-based expression
        // indexes are supported by both Postgres and SQLite (>= 3.9), so the same
        // index works in tests (sqlite :memory:) and in production (Postgres).
        // is_active is intentionally NOT in the key: an admin may keep an archived
        // inactive row alongside the active one; the resolver filters is_active.
        $this->createDeterminismIndex();
    }

    /** Cross-driver COALESCE-normalized unique index over the resolution-leg tuple. */
    private function createDeterminismIndex(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX prompts_resolution_leg_unique ON prompts ('.
            'operation_key, scope, '.
            "COALESCE(product_type, ''), ".
            'COALESCE(account_id, 0), '.
            'COALESCE(site_id, 0), '.
            'version)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('prompts');
    }
};
