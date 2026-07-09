<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Upgrade the storyboard pipeline: every PLANNING step (script, genre, characters, visual bible,
 * scene breakdown, improve-prompt) moves to the strongest live Gemini text tier, every prompt is
 * rewritten to production quality, the scene breakdown gains a per-frame motion field, and the pro
 * image model is catalogued as a non-default option. DELIBERATE overwrite of the storyboard ops'
 * models + prompts (user-requested); the seeder also clears stale default/fallback model flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new StoryboardPipelineSeeder)->run();
    }

    public function down(): void
    {
        // Config-only overwrite; nothing tenant-owned to reverse (matches prior seed migrations).
    }
};
