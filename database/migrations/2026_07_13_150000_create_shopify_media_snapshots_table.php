<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_media_snapshots — OUR OWN copy of a product's ORIGINAL Shopify gallery, taken
 * BEFORE the first destructive push to that product.
 *
 * WHY THIS TABLE EXISTS. Replacing an image (or reordering the gallery, which moves the
 * featured image) is DESTRUCTIVE on a live storefront. Shopify drops the CDN bytes once a
 * media object is deleted — so if we do not hold our own copy of the original bytes AND the
 * original order, "Undo" is a lie. The snapshot is the undo. It is therefore MANDATORY and
 * FAIL-CLOSED: a destructive push whose snapshot cannot be taken does not run.
 *
 * ONE snapshot per product (unique on (account_id, product_id)) — the original state is the
 * original state, and a later push never overwrites it. `restored_at` / `restore_count` record
 * that it was replayed; the row (and its stored bytes) are KEPT, so undo is repeatable.
 *
 * Tenant-owned: account_id NOT NULL + BelongsToAccount. The stored bytes live on the media
 * disk under accounts/{account}/sites/{site}/shopify-snapshots/{product}/ — the account leads
 * the path, so an object is never cross-tenant ambiguous and dies with the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_media_snapshots', function (Blueprint $table): void {
            $table->id();

            // Tenancy + scope — carried explicitly into every job, never inferred.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // The Shopify product GID this snapshot describes (audit + a restore that runs
            // after the local product row was re-imported).
            $table->string('external_id');

            // capturing | captured | failed. Only a CAPTURED snapshot unlocks a destructive push.
            $table->string('status', 16)->default('capturing');

            // The ordered original gallery. Each entry:
            //   { shopify_media_id, alt, position, source_url, path, mime, bytes, restored_media_id }
            // `path` is OUR opaque disk key for the downloaded original bytes — the thing that
            // makes undo real once Shopify has dropped the media.
            $table->json('media')->nullable();

            // Why a capture failed (surfaced to the merchant; the push was refused, not attempted).
            $table->text('failure_message')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->unsignedInteger('restore_count')->default(0);

            $table->timestamps();

            // One original-state snapshot per product. A second capture attempt finds this row.
            $table->unique(['account_id', 'product_id']);
            $table->index(['account_id', 'site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_media_snapshots');
    }
};
