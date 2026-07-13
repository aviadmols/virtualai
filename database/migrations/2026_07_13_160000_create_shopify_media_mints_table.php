<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_media_mints — an APPEND-ONLY record of EVERY Shopify media object we ever minted on a
 * merchant's product, written in the same breath as the productCreateMedia call that minted it.
 *
 * WHY IT EXISTS. `product_assets.shopify_media_id` is a MUTABLE POINTER. Three paths deliberately
 * move or clear it:
 *   - undo NULLs it (the image left the store);
 *   - a media Shopify processed to FAILED clears it (so a re-push can mint a fresh one);
 *   - a reclaim of a lost push OVERWRITES it with the id of the media the new attempt minted.
 * Anything built on that column therefore inherits its amnesia — and two features were:
 *   1. UNDO ("restore my original images") deleted only the LAST id an asset carried, so a media
 *      the column had forgotten stayed LIVE in the merchant's storefront forever;
 *   2. the SNAPSHOT excluded "our own image" by that same column, so a media whose link had been
 *      dropped could be captured as a merchant "original" and later RE-INJECTED by an undo.
 *
 * This table is the memory those two need, and it has exactly one rule: A ROW IS WRITTEN ONCE AND
 * NEVER UNWRITTEN. No updated_at, no soft delete, no nullable media id — like credit_ledger, a
 * correction is a new row, never an edit. If we put a media object into a merchant's live store,
 * this table knows about it, and Undo can always take it back out.
 *
 * Tenant-owned: account_id NOT NULL + BelongsToAccount. A media id is globally unique on Shopify,
 * so (account_id, shopify_media_id) is unique — a resumed / retried mint of the SAME media is one
 * row, not two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_media_mints', function (Blueprint $table): void {
            $table->id();

            // Tenancy + scope — carried explicitly into every job, never inferred.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // The asset whose image this media IS. Kept for the audit trail; the media id below
            // is the thing undo and the snapshot exclusion actually read.
            $table->foreignId('product_asset_id')->constrained('product_assets')->cascadeOnDelete();

            // The Shopify media object we put into the merchant's live gallery.
            $table->string('shopify_media_id');

            // APPEND-ONLY: created_at only. There is no updated_at because there is no update.
            $table->timestamp('created_at')->nullable();

            // One row per media object (a resumed mint of the same media collapses onto it).
            $table->unique(['account_id', 'shopify_media_id']);

            // "Every media we ever minted on THIS product" — the undo sweep + the snapshot exclusion.
            $table->index(['account_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_media_mints');
    }
};
