<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-batch generation choices (Image Studio v2): the merchant's free-text art-direction NOTE,
 * plus optional aspect-ratio / image-quality overrides. All nullable — a null keeps the operation's
 * configured default. They live on the BATCH because every asset in one batch shares them; the
 * worker reads them back to build the config, and they are folded into each asset's idempotency key
 * so a different note/ratio/quality is a genuinely different image (never a false "already exists").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_image_batches', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('source_pick');
            $table->string('aspect_ratio', 16)->nullable()->after('notes');
            $table->string('image_quality', 32)->nullable()->after('aspect_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('product_image_batches', function (Blueprint $table): void {
            $table->dropColumn(['notes', 'aspect_ratio', 'image_quality']);
        });
    }
};
