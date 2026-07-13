<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_assets.push_claim_id — the IDENTITY of the worker that currently holds the push lease.
 *
 * THE SCAR. A `pushing` asset whose worker was SIGKILLed never calls failed(), so it would sit at
 * `pushing` forever and the merchant could never push that image again. The reclaim that fixed
 * that ("past the stuck window the push is reclaimable") introduced a worse bug: the stuck check
 * reads `updated_at`, and admitting a `pushing` row NEVER RE-STAMPED IT. So a reclaim (parks=0)
 * and the original job's slow parked continuation (parks=1, still alive in a backlog) were BOTH
 * admitted inside the same window, both minted a Shopify media, and the asset row kept only the
 * LAST id — leaving an orphaned AI image live in the merchant's storefront that no undo could
 * remove.
 *
 * A CLAIM THAT NEVER RE-STAMPS THE FIELD IT IS JUDGED BY IS NOT A LEASE — IT IS A SECOND DOOR.
 *
 * So the claim now has both halves, written together inside the row-locked transaction:
 *   - push_claim_id : WHO holds it. A reclaim mints a NEW id, which EVICTS the old worker: it
 *                     re-checks its claim before minting media and stands down when it is stale.
 *   - updated_at    : HOW FRESH it is. Re-stamped on every admission, so a second worker looking
 *                     at the same row does not see a lease that is 31 minutes old.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->string('push_claim_id', 64)->nullable()->after('push_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_assets', function (Blueprint $table): void {
            $table->dropColumn('push_claim_id');
        });
    }
};
