<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * banners — a merchant-authored, AI-generated promotional banner shown on the storefront.
 *
 * Tenant-owned (account_id NOT NULL + BelongsToAccount), site-scoped. A banner carries the
 * SELECTED generated artwork (image_path/mime/dims — public marketing media, not a signed
 * per-shopper URL), its composition (a full AI image, or an AI image + our HTML text/CTA
 * overlay), the click target, the visually-picked host-page placements, and the display
 * rules (audience / page context / schedule / frequency / locale). The status machine is
 * guarded on the model (draft -> active|archived; active|paused <-> ; -> archived terminal).
 *
 * selected_asset_id points at the banner_assets row the merchant chose; it is a plain
 * nullable id (no DB FK) to avoid a circular banners<->banner_assets constraint — the app
 * keeps it consistent (a deleted asset cascades from banner_assets, not the other way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            // Tenancy — the isolation boundary.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            $table->string('name');

            // draft | active | paused | archived. Guarded transitions on the model.
            $table->string('status', 16)->default('draft');

            // image | overlay (full AI image vs AI image + our crisp HTML headline/CTA).
            $table->string('composition', 16)->default('image');

            // The chosen banner_assets row (app-maintained; no circular FK).
            $table->unsignedBigInteger('selected_asset_id')->nullable();

            // The selected artwork, copied onto the banner for the widget payload. PUBLIC
            // marketing media (a stable public URL), never a private signed path.
            $table->string('image_path')->nullable();
            $table->string('image_mime', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            // Where a click leads + a11y text.
            $table->string('target_url')->nullable();
            $table->string('alt_text')->nullable();

            // overlay: {headline, subtext, cta_label} (overlay composition only).
            $table->json('overlay')->nullable();

            // placements: [{selector, position}] — visually picked, allow-list validated.
            $table->json('placements')->nullable();

            // rules: {audience, pages, schedule, frequency, locales} — display targeting.
            $table->json('rules')->nullable();

            $table->timestamps();

            // The merchant banner list, filtered by status.
            $table->index(['account_id', 'site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
