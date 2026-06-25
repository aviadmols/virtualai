<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * products — a scanned PDP. Tenant-owned: account_id NOT NULL + BelongsToAccount;
 * site-scoped (site_id) because selectors/prompts/gallery live on the Site.
 *
 * A scan NEVER auto-approves: a fresh scan persists as status=draft and only the
 * merchant's confirm() transitions it to confirmed (the only path that goes live).
 * Per-field confidence + the full extraction provenance ride in JSON columns so
 * the confirm/correct UI can surface low-confidence fields for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary. account_id NOT NULL + FK + index.
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            // Sub-scope within the account.
            $table->foreignId('site_id')
                ->constrained('sites')->cascadeOnDelete();

            // The merchant-pasted product-page URL the scan ran against.
            $table->text('source_url');
            // sha1(url) — half of the scan idempotency key, indexed for re-scan lookups.
            $table->string('source_url_hash', 40);

            // Status machine: draft (scanned, needs review) -> confirmed (live).
            // failed is a terminal scan outcome (bot-block / render-empty / invalid).
            $table->string('status', 16)->default('draft');

            // --- Extracted product fields (each editable in the confirm UI) ---
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('product_type')->nullable();

            // Locale-aware price: integer MINOR units (never a lossy float) + the
            // ISO currency. sale/regular captured separately; is_range flags "from".
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('sale_price_minor')->nullable();
            $table->unsignedBigInteger('regular_price_minor')->nullable();
            $table->boolean('price_is_range')->default(false);

            // Resolved (lazy/srcset) absolute image URLs.
            $table->text('main_image_url')->nullable();
            $table->json('images')->nullable();

            // Best-effort spec/measurements table extraction.
            $table->json('physical_dimensions')->nullable();

            // Per-field confidence {field => {value?, confidence, source}} so the
            // review queue + UI can flag low-confidence guesses for the merchant.
            $table->json('field_confidence')->nullable();

            // The detected page selectors (role => {primary, fallback_chain[],
            // confidence, matched_count}) — selectors are also mirrored to the
            // Site (site-level config) on confirm; the draft snapshot lives here.
            $table->json('detected_selectors')->nullable();

            // The model's strict JSON + representation provenance, for re-review.
            $table->json('scan_raw')->nullable();

            // How the page was fetched (http | headless) and any merchant-facing
            // warnings raised during mapping (currency inferred, multi-match, ...).
            $table->string('fetched_via', 16)->nullable();
            $table->json('warnings')->nullable();

            // Overall scan confidence (the aggregate the threshold reads).
            $table->decimal('confidence', 4, 3)->nullable();

            // Set when the merchant confirms; null while draft.
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            // Hot-path: a product lookup within an account; a re-scan lookup by url.
            $table->index(['account_id', 'site_id']);
            $table->index(['account_id', 'site_id', 'source_url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
