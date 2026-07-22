<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_directives — the Super-Admin "global rules" per surface.
 *
 * A platform-wide directive (art-direction / constraints) appended to the SYSTEM prompt of every
 * generation of a surface, across ALL sites — the platform-wide equivalent of the merchant's
 * per-batch note. One row per surface ('image_studio' | 'try_on'). `version` is bumped on each
 * meaningful edit so it folds into the generation idempotency keys (a rule change re-generates
 * instead of colliding as a duplicate). Platform-global (not tenant-scoped) — on GlobalModels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_directives', function (Blueprint $table): void {
            $table->id();
            $table->string('surface', 32)->unique(); // image_studio | try_on
            $table->text('rules')->nullable();        // the directive text (empty/inactive = no-op)
            $table->unsignedInteger('version')->default(1); // bumped on each meaningful edit
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_directives');
    }
};
