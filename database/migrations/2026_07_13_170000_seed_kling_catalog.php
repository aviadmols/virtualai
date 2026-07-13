<?php

use Database\Seeders\KlingCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Catalog the Kling (Kuaishou) models in the DB control plane: the six verified image ids on every
 * image operation, and the legacy-surface video ids on the storyboard clip step.
 *
 * A migration, not just a seeder, so an EXISTING deployment gets the catalog on deploy without a
 * manual seed run (the Kling client shipped with nothing selectable). It is an idempotent
 * updateOrCreate of the DEFAULTS only: nothing is made default/fallback (fal keeps every default)
 * and nothing tenant-owned is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new KlingCatalogSeeder)->run();
    }

    public function down(): void
    {
        // Config-only seed; nothing tenant-owned to reverse (matches the prior seed migrations).
    }
};
