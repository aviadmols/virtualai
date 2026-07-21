<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * style_presets — the platform super-admin's GLOBAL library of visual generation styles.
 *
 * Each preset = a base operation (packshot/on_model/try_on/banner — which sets the surface +
 * model), a prompt, an uploaded reference image, and an admin-generated SAMPLE. Only an
 * APPROVED + active preset appears in a merchant/shopper style slider. GLOBAL (no account_id;
 * on GlobalModels::ALLOW_LIST) — it holds no tenant data and never charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // The base AI operation (AiOperation::KEY_*). Sets the surface (packshot/on_model =>
            // Image Studio, try_on => Try-On, banner => Banners) AND the model/quality.
            $table->string('operation_key', 40);
            // The style prompt (with {{tokens}}); substituted with strtr at generation time.
            $table->text('user_prompt');
            // Admin-uploaded reference image (private disk path) — drives the sample + is the thumbnail.
            $table->string('reference_image_path')->nullable();
            // The generated sample (private disk path) + its state.
            $table->string('sample_image_path')->nullable();
            $table->string('sample_status', 16)->default('pending'); // pending / ready / failed
            // Curation: only 'approved' + active presets are offered to merchants/shoppers.
            $table->string('status', 16)->default('draft'); // draft / approved
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            // The slider query: approved + active presets for a surface's operation(s), ordered.
            $table->index(['operation_key', 'status', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_presets');
    }
};
