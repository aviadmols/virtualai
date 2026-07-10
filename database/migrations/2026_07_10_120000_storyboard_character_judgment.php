<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Character JUDGMENT for the storyboard prompts: a reference image now locks only the character's
 * IDENTITY (face, age, hair, marks, proportions) while the WARDROBE is designed to fit the story's
 * world and role (armor for a knight — optionally keeping signature items when the tone fits);
 * the genre step receives the short-film format so its pacing fits; the scene breakdown must tell
 * a COMPLETE arc with a resolved final frame; reference images never contribute background/pose/
 * lighting. Full re-seed of the storyboard ops (the prompt upgrades are the point).
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
