<?php

use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed the two Product Image Studio operations into the DB control plane:
 * `packshot_generation` and `on_model_generation` — each with its own model, fallback,
 * prompt, aspect ratio, params and (admin-settable) credit multiplier.
 *
 * A migration, not just a seeder, so an EXISTING deployment gets them on deploy without a
 * manual seed run. It is an updateOrCreate re-seed of the DEFAULTS only — a Super-Admin who
 * later re-points a model or edits a prompt owns those rows; nothing tenant-owned is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AiControlPlaneSeeder)->seedProductImageOperations();
    }

    public function down(): void
    {
        // Config-only seed; nothing tenant-owned to reverse (matches the prior seed migrations).
    }
};
