<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed the storyboard pipeline operations (steps + prompts + models) on DEPLOY.
 *
 * The seeder is idempotent (updateOrCreate), but it only runs via `db:seed` — which a deploy does
 * NOT run — so without this the storyboard AiOperations are missing in production and the pipeline
 * fails on the first step ("operation not configured"). Running it from a migration guarantees the
 * steps exist after every deploy (predeploy runs migrations). Config data only — no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new StoryboardPipelineSeeder())->run();
    }

    public function down(): void
    {
        // Config seed — nothing to roll back.
    }
};
