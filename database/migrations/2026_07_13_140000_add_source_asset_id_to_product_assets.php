<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_assets.source_asset_id — the asset this one was REGENERATED from.
 *
 * It exists for the money path, not for decoration. "Regenerate" is the ONE place the
 * deterministic asset key is meant to VARY, so its client_request_id segment must be derived
 * from the merchant's INTENT ("give me another render of THIS image") and not minted fresh on
 * every click — a random segment per click means a double-clicked button produces two assets,
 * two provider renders and two charge rows.
 *
 * The intent id is therefore regen-{source_asset_id}-{n}, where n counts the regenerations of
 * that source that have already SETTLED (RegenerateProductImage). This column is what makes n
 * countable: two clicks of the same button see the same n, hash to the same key, and collide on
 * the UNIQUE idempotency_key — one asset, one render, one charge. A deliberate later regenerate
 * (after the previous one settled) sees n+1 and correctly mints a new, separately-charged asset.
 *
 * Nullable: a normal batch asset has no source asset. Self-referencing FK, nullOnDelete so a
 * purged parent never cascades into a child that carries its own ledger row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->foreignId('source_asset_id')
                ->nullable()
                ->after('batch_id')
                ->constrained('product_assets')
                ->nullOnDelete();

            // The regenerate counter reads (source, status) — account_id leads, as everywhere.
            $table->index(['account_id', 'source_asset_id', 'status'], 'product_assets_regen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->dropIndex('product_assets_regen_idx');
            $table->dropConstrainedForeignId('source_asset_id');
        });
    }
};
